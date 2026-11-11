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
 * It starts from the live row and undoes newer rows newest-first ({@see PayloadEncoder::undo()}),
 * so it never needs a stored snapshot. Rows are ordered by `created_at`, `changed_at_micros`, then
 * `id`: backfilled rows describe older history but carry newer ids, and deferred writes can land out
 * of change order. Writes that bypass model events are invisible here.
 */
final class History
{
    /**
     * Each row of one loggable, newest first, with the raw attributes right after and right
     * before it. A state is null where the model did not exist or nothing is known.
     *
     * @param  (callable(Builder<SystemLog>): mixed)|null  $constrain
     * @return Generator<int, array{0: SystemLog, 1: array<string, mixed>|null, 2: array<string, mixed>|null}>
     */
    public static function walk(string $loggableType, string|int $loggableId, ?callable $constrain = null): Generator
    {
        $state = self::live($loggableType, $loggableId);

        $rows = self::rows($loggableType, $loggableId)
            ->when($constrain !== null, $constrain)
            ->cursor();

        foreach ($rows as $row) {
            if ($state === null && $row->event !== 'created' && ($snapshot = $row->storedAttributes()) !== null) {
                $state = $snapshot;
            }

            $after = $state;

            $state = match ($row->event) {
                'created' => null,
                'deleted' => $state,
                default => $state === null ? null : PayloadEncoder::undo($state, $row->original ?? []),
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

        foreach (self::walk($loggableType, $loggableId) as [$row, $after, $previous]) {
            if ($point instanceof SystemLog ? $row->is($point) : $row->created_at <= $point) {
                return $after;
            }

            $before = $previous;
        }

        return $point instanceof SystemLog ? null : $before;
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

        return (new $class)->newQueryWithoutScopes()->find($loggableId)?->getAttributes();
    }

    /**
     * @return Builder<SystemLog>
     */
    public static function rows(string $loggableType, string|int $loggableId): Builder
    {
        /** @var class-string<SystemLog> $model */
        $model = config('system-log.database.model', SystemLog::class);

        return $model::query()
            ->where('loggable_type', $loggableType)
            ->where('loggable_id', (string) $loggableId)
            ->orderByDesc('created_at')
            ->orderByDesc('changed_at_micros')
            ->orderByDesc('id');
    }
}
