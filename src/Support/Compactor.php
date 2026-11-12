<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Support;

use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Model;
use ViicSlen\SystemLog\Models\SystemLog;

/**
 * Merges a loggable's update rows into one row per calendar month.
 *
 * Each run of consecutive `updated` / `compacted` rows inside one month becomes a single
 * `compacted` row (the run's newest row, kept in place so the ordering holds). Its `original` /
 * `modified` hold each column's value before the run's first change and after its last, encoded
 * from the rebuilt month-start and month-end states — so patches and JSON diffs are recomputed,
 * not concatenated, and undoing the row lands exactly on the month-start state.
 */
final class Compactor
{
    public const string EVENT = 'compacted';

    /** A live row written this recently may still have its own log row in flight (deferred). */
    private const int QUIET_MINUTES = 10;

    /**
     * Compact the months that end before `$before`. Returns the number of rows removed, or null
     * when the model was skipped because it changed too recently.
     */
    public static function compact(string $loggableType, string|int $loggableId, DateTimeInterface $before): ?int
    {
        $class = LogOptions::modelClass($loggableType);
        $options = LogOptions::for($class);
        $casts = is_subclass_of($class, Model::class) ? (new $class)->getCasts() : [];

        $run = static function () use ($class, $loggableType, $loggableId, $before, $options, $casts): ?int {
            if (self::changedRecently($class, $loggableId)) {
                return null;
            }

            return self::logModel()->getConnection()->transaction(function () use ($loggableType, $loggableId, $before, $options, $casts): int {
                $removed = 0;

                // Merged as each run closes, so memory holds one month of one model at a time.
                foreach (self::segments($loggableType, $loggableId, $before) as $segment) {
                    $removed += self::merge($segment, $options, $casts);
                }

                return $removed;
            });
        };

        // The live row stays locked while it is walked: a write landing mid-compaction would anchor
        // the walk on a value the older rows do not lead back from.
        return is_subclass_of($class, Model::class) ? (new $class)->getConnection()->transaction($run) : $run();
    }

    /** Merged-away rows deleted per statement while a run is still being read. */
    private const int DELETE_BATCH = 1000;

    /**
     * Runs of eligible rows, folded as they are read: the run keeps its newest row, the state
     * after it and before its oldest, and per column the oldest stored old value and the newest
     * stored new value. The other rows are deleted in batches as the run grows.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private static function segments(string $loggableType, string|int $loggableId, DateTimeInterface $before): Generator
    {
        $current = null;

        foreach (History::walk($loggableType, $loggableId) as [$row, $after, $previous]) {
            $eligible = in_array($row->event, ['updated', self::EVENT], true) && $row->created_at < $before;
            $month = $row->created_at->format('Y-m');

            if ($current !== null && (! $eligible || $current['month'] !== $month)) {
                yield $current;
                $current = null;
            }

            if (! $eligible) {
                continue;
            }

            $current ??= [
                'month' => $month, 'keeper' => $row, 'end' => $after, 'count' => 0, 'changes' => 0,
                'users' => [], 'modified' => [], 'original' => [], 'anchored' => false, 'pinned' => [], 'pending' => [], 'removed' => 0,
            ];

            $current['start'] = $previous;
            $current['count']++;
            $current['changes'] += $row->event === self::EVENT ? (int) ($row->metadata['changes'] ?? 1) : 1;
            $current['from'] = $row->event === self::EVENT
                ? $row->metadata['from'] ?? $row->getRawOriginal('created_at')
                : $row->getRawOriginal('created_at');
            $current['anchored'] = $current['anchored'] || $row->storedAttributes() !== null;
            $current['modified'] += $row->modified ?? [];
            // Newest stored evidence per column (per key, for JSON diffs); a row's change sits over its snapshot.
            $evidence = $row->storedAttributes() ?? [];

            foreach ($row->modified ?? [] as $column => $value) {
                $evidence[$column] = array_key_exists($column, $evidence) ? PayloadEncoder::over($value, $evidence[$column]) : $value;
            }

            foreach ($evidence as $column => $value) {
                $current['pinned'][$column] = array_key_exists($column, $current['pinned'])
                    ? PayloadEncoder::over($current['pinned'][$column], $value)
                    : $value;
            }

            foreach ($row->original ?? [] as $column => $value) {
                $current['original'][$column] = $value;
            }

            foreach ([...($row->event === self::EVENT ? $row->metadata['users'] ?? [] : []), $row->user_id] as $user) {
                if ($user !== null) {
                    $current['users'][$user] = true;
                }
            }

            if ($row->isNot($current['keeper'])) {
                $current['pending'][] = $row->id;
            }

            if (count($current['pending']) >= self::DELETE_BATCH) {
                $current['removed'] += self::delete($current['pending']);
                $current['pending'] = [];
            }
        }

        if ($current !== null) {
            yield $current;
        }
    }

    /**
     * @param  array<string, mixed>  $segment
     * @param  array<string, string>  $casts
     */
    private static function merge(array $segment, LogOptions $options, array $casts): int
    {
        /** @var SystemLog $keeper */
        $keeper = $segment['keeper'];

        if ($segment['count'] === 1 && $keeper->event === self::EVENT) {
            return 0;
        }

        $start = $segment['start'] ?? [];
        // Each column ends at the run's own newest stored value (a change or a snapshot), not at what
        // newer state carries back: a change whose log row is still deferred is already in it.
        $end = $segment['end'] === null ? null : PayloadEncoder::redo($segment['end'], $segment['pinned']);
        $endState = $end ?? [];
        $modified = [];
        $original = [];

        // A snapshot in the run pins columns no recorded change touched, so the start can differ
        // from the end there too; carry those as well, or the end snapshot would overwrite them.
        $columns = $segment['modified'] + $segment['original'] + ($segment['anchored'] ? $start + $endState : []);

        foreach (array_keys(PayloadEncoder::withoutExcluded($options, $columns)) as $column) {
            if (array_key_exists($column, $start) && array_key_exists($column, $endState)) {
                if ($start[$column] === $endState[$column]) {
                    continue;
                }

                [$new, $old] = PayloadEncoder::change($options, [$column => $start[$column]], [$column => $endState[$column]], $casts);
                $modified[$column] = $new[$column];
                $original[$column] = $old[$column];

                continue;
            }

            // An unrecoverable end of the run: keep the oldest stored old value and the newest new one.
            if (array_key_exists($column, $segment['original'])) {
                $original[$column] = $segment['original'][$column];
            }

            if (array_key_exists($column, $segment['modified'])) {
                $modified[$column] = $segment['modified'][$column];
            }
        }

        $users = array_keys($segment['users']);

        $keeper->forceFill([
            'event' => self::EVENT,
            // A run that held a snapshot keeps one at its end, so values a write outside model
            // events changed stay pinned for the months before it.
            'attributes' => $segment['anchored'] && $end !== null ? PayloadEncoder::snapshot($options, $end, $casts) : null,
            'modified' => $modified,
            'original' => $original,
            'trace' => null,
            'user_id' => count($users) === 1 ? $users[0] : null,
            'metadata' => [
                'period' => $segment['month'],
                // When the run's first change happened: the merged rows before the kept one are gone.
                'from' => $segment['from'],
                'changes' => $segment['changes'],
                'users' => $users,
            ],
        ])->saveQuietly();

        return $segment['removed'] + self::delete($segment['pending']);
    }

    /**
     * @param  list<int>  $ids
     */
    private static function delete(array $ids): int
    {
        return $ids === [] ? 0 : self::logModel()->newQuery()->whereKey($ids)->delete();
    }

    private static function logModel(): SystemLog
    {
        /** @var class-string<SystemLog> $model */
        $model = config('system-log.database.model', SystemLog::class);

        return new $model;
    }

    /**
     * Read under a row lock on the primary, so the check holds for as long as the walk runs.
     */
    private static function changedRecently(string $class, string|int $loggableId): bool
    {
        if (! is_subclass_of($class, Model::class)) {
            return false;
        }

        $model = new $class;
        $column = $model->getUpdatedAtColumn();
        $live = $model->newQueryWithoutScopes()->lockForUpdate()->find($loggableId)?->getAttributes();

        return $model->usesTimestamps()
            && $column !== null
            && isset($live[$column])
            && $model->fromDateTime(now()->subMinutes(self::QUIET_MINUTES)) < $live[$column];
    }
}
