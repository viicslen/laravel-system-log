<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ViicSlen\SystemLog\Models\SystemLog;
use ViicSlen\SystemLog\Observers\ModelActivityObserver;

/**
 * Drop this trait onto any Eloquent model to enable automatic activity logging.
 *
 * The boot method registers the observer once per model class, so it is safe to
 * include the trait on base model classes (the observer is only registered once).
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasSystemLogs
{
    protected static function bootHasSystemLogs(): void
    {
        // Guard: if logging is disabled globally, skip observer registration.
        if (! config('system-log.enabled', true)) {
            return;
        }

        static::observe(ModelActivityObserver::class);
    }

    /**
     * @return MorphMany<SystemLog, static>
     */
    public function systemLogs(): MorphMany
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
        $model = config('system-log.model', SystemLog::class);

        return $this->morphMany($model, 'loggable');
    }
}
