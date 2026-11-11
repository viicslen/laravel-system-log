<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

        if (self::changedRecently($class, $loggableType, $loggableId)) {
            return null;
        }

        $options = LogOptions::for($class);
        $casts = is_subclass_of($class, Model::class) ? (new $class)->getCasts() : [];

        return DB::connection((new SystemLog)->getConnectionName())->transaction(function () use ($loggableType, $loggableId, $before, $options, $casts): int {
            $removed = 0;

            foreach (self::segments($loggableType, $loggableId, $before) as $segment) {
                $removed += self::merge($segment, $options, $casts);
            }

            return $removed;
        });
    }

    /**
     * Runs of eligible rows, each with the state after its newest row and before its oldest.
     *
     * @return list<array{rows: list<SystemLog>, end: array<string, mixed>|null, start: array<string, mixed>|null}>
     */
    private static function segments(string $loggableType, string|int $loggableId, DateTimeInterface $before): array
    {
        $segments = [];
        $current = null;

        foreach (History::walk($loggableType, $loggableId) as [$row, $after, $previous]) {
            $eligible = in_array($row->event, ['updated', self::EVENT], true) && $row->created_at < $before;
            $month = $row->created_at->format('Y-m');

            if ($current !== null && (! $eligible || $current['month'] !== $month)) {
                $segments[] = $current;
                $current = null;
            }

            if (! $eligible) {
                continue;
            }

            $current ??= ['month' => $month, 'rows' => [], 'end' => $after, 'start' => null];
            $current['rows'][] = $row;
            $current['start'] = $previous;
        }

        if ($current !== null) {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * @param  array{month: string, rows: list<SystemLog>, end: array<string, mixed>|null, start: array<string, mixed>|null}  $segment
     * @param  array<string, string>  $casts
     */
    private static function merge(array $segment, LogOptions $options, array $casts): int
    {
        $rows = $segment['rows'];
        $keeper = $rows[0];

        if (count($rows) === 1 && $keeper->event === self::EVENT) {
            return 0;
        }

        $oldestFirst = array_reverse($rows);
        $columns = array_unique(array_merge(...array_map(
            static fn (SystemLog $row): array => array_keys(($row->modified ?? []) + ($row->original ?? [])),
            $rows,
        )));

        $modified = [];
        $original = [];

        foreach ($columns as $column) {
            $start = $segment['start'] ?? [];
            $end = $segment['end'] ?? [];

            if (array_key_exists($column, $start) && array_key_exists($column, $end)) {
                if ($start[$column] === $end[$column]) {
                    continue;
                }

                [$new, $old] = PayloadEncoder::change($options, [$column => $start[$column]], [$column => $end[$column]], $casts);
                $modified[$column] = $new[$column];
                $original[$column] = $old[$column];

                continue;
            }

            // An unrecoverable end of the run: keep the first stored old value and the last new one.
            foreach ($oldestFirst as $row) {
                if (array_key_exists($column, $row->original ?? [])) {
                    $original[$column] ??= $row->original[$column];
                }

                if (array_key_exists($column, $row->modified ?? [])) {
                    $modified[$column] = $row->modified[$column];
                }
            }
        }

        $changes = 0;
        $users = [];

        foreach ($rows as $row) {
            $changes += $row->event === self::EVENT ? (int) ($row->metadata['changes'] ?? 1) : 1;
            $users = [...$users, ...($row->metadata['users'] ?? []), $row->user_id];
        }

        $users = array_values(array_unique(array_filter($users, static fn (mixed $user): bool => $user !== null)));

        $keeper->forceFill([
            'event' => self::EVENT,
            'attributes' => null,
            'modified' => $modified,
            'original' => $original,
            'trace' => null,
            'user_id' => count($users) === 1 ? $users[0] : null,
            'metadata' => [
                'period' => $segment['month'],
                'changes' => $changes,
                'users' => $users,
            ],
        ])->saveQuietly();

        $others = array_map(static fn (SystemLog $row): int => $row->id, array_slice($rows, 1));

        if ($others !== []) {
            $keeper->newQuery()->whereKey($others)->delete();
        }

        return count($others);
    }

    private static function changedRecently(string $class, string $loggableType, string|int $loggableId): bool
    {
        if (! is_subclass_of($class, Model::class)) {
            return false;
        }

        $model = new $class;
        $column = $model->getUpdatedAtColumn();
        $live = History::live($loggableType, $loggableId);

        return $model->usesTimestamps()
            && $column !== null
            && isset($live[$column])
            && $model->fromDateTime(now()->subMinutes(self::QUIET_MINUTES)) < $live[$column];
    }
}
