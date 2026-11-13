<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Support;

use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ViicSlen\SystemLog\Models\SystemLog;

/**
 * Rebuilds a loggable's past attribute states from its log rows.
 *
 * It starts from the live row and walks the rows newest-first, so it never needs a stored snapshot:
 * each row's state takes the values the row itself stored (its snapshot, or its `modified`), and
 * the state before it is that with the row's `original` undone ({@see PayloadEncoder::undo()}).
 * A column a write outside model events changed carries its later value back only until the
 * nearest row that stored it. Rows are ordered by `created_at`, `changed_at_micros`, then `id`:
 * backfilled rows describe older history but carry newer ids, and deferred writes can land out of
 * change order.
 */
final class History
{
    /** Rows read per query while walking. */
    private const int BATCH = 500;

    /**
     * Each row of one loggable, newest first, with the raw attributes right after and right
     * before it. A state is null where the model did not exist or nothing is known.
     *
     * @return Generator<int, array{0: SystemLog, 1: array<string, mixed>|null, 2: array<string, mixed>|null}>
     */
    public static function walk(string $loggableType, string|int $loggableId): Generator
    {
        $state = self::live($loggableType, $loggableId);
        $deletedAt = self::deletedAtColumn($loggableType);

        foreach (self::rowsNewestFirst($loggableType, $loggableId) as $row) {
            $stored = $row->storedAttributes();

            if ($row->event === 'deleted') {
                // A hard-deleted model is absent after its delete. A soft delete only stamped
                // `deleted_at`, through a query that fires no `updated` event, so undo that here.
                $after = $state === null || $stored === null ? $state : PayloadEncoder::redo($state, $stored);
                $state = match (true) {
                    $after === null => $stored,
                    $deletedAt === null => $after,
                    default => [...$after, $deletedAt => null],
                };

                yield [$row, $after, $state];

                continue;
            }

            $after = match (true) {
                $stored !== null => PayloadEncoder::redo($state ?? [], $stored),
                $state === null => null,
                default => PayloadEncoder::redo($state, $row->modified ?? []),
            };

            $state = match ($row->event) {
                'created' => null,
                default => $after === null ? null : PayloadEncoder::undo($after, $row->original ?? []),
            };

            yield [$row, $after, $state];
        }
    }

    /**
     * Raw attributes right after `$point` (a log row), or as of `$point` (a time).
     *
     * @return array<string, mixed>|null
     */
    public static function stateAt(string $loggableType, string|int $loggableId, SystemLog|DateTimeInterface $point): ?array
    {
        $before = self::live($loggableType, $loggableId);
        $micros = $point instanceof DateTimeInterface ? (int) $point->format('Uu') : null;

        foreach (self::walk($loggableType, $loggableId) as [$row, $after, $previous]) {
            $reached = $point instanceof SystemLog
                ? $row->is($point)
                : ($row->changed_at_micros !== null ? $row->changed_at_micros <= $micros : $row->created_at <= $point);

            if ($reached) {
                return $after;
            }

            $before = $previous;
        }

        return $point instanceof SystemLog ? null : $before;
    }

    private static function deletedAtColumn(string $loggableType): ?string
    {
        $class = LogOptions::modelClass($loggableType);

        return is_subclass_of($class, Model::class) && method_exists($class, 'getDeletedAtColumn')
            ? (new $class)->getDeletedAtColumn()
            : null;
    }

    /**
     * Raw attributes of the live row, trashed included, or null when it is gone.
     *
     * @return array<string, mixed>|null
     */
    public static function live(string $loggableType, string|int $loggableId): ?array
    {
        $class = LogOptions::modelClass($loggableType);

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        // The primary: a lagging replica would anchor the walk on a value the newest rows do not lead back from.
        return (new $class)->newQueryWithoutScopes()->useWritePdo()->find($loggableId)?->getAttributes();
    }

    /**
     * @return Builder<SystemLog>
     */
    public static function rows(string $loggableType, string|int $loggableId): Builder
    {
        /** @var class-string<SystemLog> $model */
        $model = config('system-log.database.model', SystemLog::class);

        return $model::query()
            ->select(['id', 'loggable_type', 'loggable_id', 'event', 'attributes', 'modified', 'original', 'user_id', 'metadata', 'created_at', 'changed_at_micros'])
            ->where('loggable_type', $loggableType)
            ->where('loggable_id', (string) $loggableId);
    }

    /**
     * Keyset batches rather than a cursor: a buffered cursor holds the whole history in memory.
     *
     * @return Generator<int, SystemLog>
     */
    private static function rowsNewestFirst(string $loggableType, string|int $loggableId): Generator
    {
        $last = null;

        do {
            $batch = self::rows($loggableType, $loggableId)
                ->when($last !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('created_at', '<', $last->getRawOriginal('created_at'))
                    ->orWhere(fn (Builder $query) => $query
                        ->where('created_at', $last->getRawOriginal('created_at'))
                        ->where(fn (Builder $query) => $query
                            // Rows from before `changed_at_micros` existed count as 0: first in their second.
                            ->whereRaw('coalesce(changed_at_micros, 0) < ?', [$last->changed_at_micros ?? 0])
                            ->orWhere(fn (Builder $query) => $query
                                ->whereRaw('coalesce(changed_at_micros, 0) = ?', [$last->changed_at_micros ?? 0])
                                ->where('id', '<', $last->id))))))
                ->orderByDesc('created_at')
                ->orderByRaw('coalesce(changed_at_micros, 0) desc')
                ->orderByDesc('id')
                ->limit(self::BATCH)
                ->get();

            foreach ($batch as $row) {
                yield $row;
            }

            $last = $batch->last();
        } while ($batch->count() === self::BATCH);
    }
}
