<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The storage options in effect for one loggable model: the global config with that model's
 * `system-log.models` entry merged over it.
 */
final readonly class LogOptions
{
    public const string FULL = 'full';

    public const string DIFF = 'diff';

    /**
     * @param  list<string>  $exclude
     * @param  array<string, string>  $columnStrategies
     * @param  array<string, string>  $castStrategies
     */
    public function __construct(
        public string $mode,
        public bool $snapshotCreated,
        public bool $snapshotDeleted,
        public array $exclude,
        public ?int $maxValueLength,
        public string $strategy,
        public array $columnStrategies,
        public array $castStrategies,
        public bool $compaction,
        public int $compactAfterDays,
        public bool $prune,
        public int $retentionDays,
        public int $snapshotEvery = 0,
    ) {}

    /**
     * @param  Model|class-string<Model>  $model  a model, its class, or its morph alias
     */
    public static function for(Model|string $model): self
    {
        $class = self::modelClass($model);
        $global = (array) config('system-log');
        $overrides = (array) (config('system-log.models')[$class] ?? []);
        $get = static fn (string $key, mixed $default = null): mixed => data_get($overrides, $key, data_get($global, $key, $default));

        $length = $get('attributes.max_value_length', 1000);
        $retention = (int) $get('prune.retention_days', 0);

        return new self(
            mode: $get('payload.mode', self::FULL) === self::DIFF ? self::DIFF : self::FULL,
            snapshotCreated: (bool) $get('payload.snapshot_created', true),
            snapshotDeleted: (bool) $get('payload.snapshot_deleted', true),
            exclude: array_values((array) $get('exclude', [])),
            maxValueLength: $length === null ? null : (int) $length,
            strategy: (string) $get('attributes.strategy', PayloadEncoder::TRUNCATE),
            columnStrategies: (array) $get('attributes.columns', []),
            castStrategies: (array) $get('attributes.casts', []),
            compaction: (bool) $get('compaction.enabled', false),
            compactAfterDays: (int) $get('compaction.after_days', 60),
            prune: (bool) $get('prune.enabled', true) && $retention > 0,
            retentionDays: $retention,
            snapshotEvery: max(0, (int) $get('payload.snapshot_every', 0)),
        );
    }

    /**
     * Model classes with their own `system-log.models` entry.
     *
     * @return list<class-string<Model>>
     */
    public static function configuredModels(): array
    {
        return array_keys((array) config('system-log.models', []));
    }

    /**
     * @return class-string<Model>|string
     */
    public static function modelClass(Model|string $model): string
    {
        if ($model instanceof Model) {
            return $model::class;
        }

        return Relation::getMorphedModel($model) ?? $model;
    }

    /**
     * @param  class-string<Model>  $class
     */
    public static function morphClass(string $class): string
    {
        return (new $class)->getMorphClass();
    }
}
