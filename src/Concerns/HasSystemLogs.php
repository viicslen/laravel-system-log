<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReflectionClass;
use ViicSlen\SystemLog\Models\SystemLog;
use ViicSlen\SystemLog\Observers\ModelActivityObserver;

/**
 * Drop this trait onto any Eloquent model to enable automatic activity logging.
 *
 * The boot method registers the observer once per model class, so it is safe to
 * include the trait on base model classes (the observer is only registered once).
 *
 * @mixin Model
 *
 * @phpstan-require-extends Model
 */
trait HasSystemLogs
{
    protected static function bootHasSystemLogs(): void
    {
        // Guard: if logging is disabled globally, skip observer registration.
        if (! config('system-log.enabled', true)) {
            return;
        }

        static::registerSystemLogObserver(ModelActivityObserver::class);
    }

    /**
     * Bind the observer's handlers to this model's events.
     *
     * Model::observe() cannot be used here: it does `new static` internally, and
     * from Laravel 13 instantiating a model while that same model is booting
     * throws a LogicException. This registers the observer the same way
     * Model::registerObserver() does, without needing an instance.
     *
     * @param  class-string  $observer
     */
    protected static function registerSystemLogObserver(string $observer): void
    {
        /** @var array<int, string> $observables */
        $observables = (new ReflectionClass(static::class))->getDefaultProperties()['observables'] ?? [];

        $events = array_merge([
            'retrieved', 'creating', 'created', 'updating', 'updated',
            'saving', 'saved', 'restoring', 'restored', 'replicating',
            'trashed', 'deleting', 'deleted', 'forceDeleting', 'forceDeleted',
        ], $observables);

        foreach ($events as $event) {
            if (method_exists($observer, $event)) {
                static::registerModelEvent($event, $observer.'@'.$event);
            }
        }
    }

    /**
     * @return MorphMany<SystemLog, static>
     */
    public function systemLogs(): MorphMany
    {
        /** @var class-string<Model> $model */
        $model = config('system-log.database.model', SystemLog::class);

        return $this->morphMany($model, 'loggable');
    }
}
