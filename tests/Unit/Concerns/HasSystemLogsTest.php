<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Queue;
use ViicSlen\SystemLog\Concerns\HasSystemLogs;
use ViicSlen\SystemLog\Models\SystemLog;

covers(HasSystemLogs::class);

// Shared test model definition
function makeLoggableModel(): Model
{
    return new class extends Model
    {
        use HasSystemLogs;

        protected $table = 'test_models';

        protected $fillable = ['name', 'email'];
    };
}

it('registers observer when trait is booted', function () {
    Queue::fake();

    $modelClass = get_class(makeLoggableModel());

    // Create and persist a model to trigger the observer via Eloquent
    $model = new $modelClass;
    $model->name = 'Test';
    $model->save();

    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBeGreaterThanOrEqual(1);
});

it('does not register observer when system-log is disabled', function () {
    Queue::fake();

    config()->set('system-log.enabled', false);

    $model = new class extends Model
    {
        use HasSystemLogs;

        protected $table = 'test_models';

        protected $fillable = ['name', 'email'];
    };

    // Re-boot to pick up disabled config
    $modelClass = get_class($model);
    // Flush listeners so bootHasSystemLogs runs fresh with disabled config
    $modelClass::flushEventListeners();
    $modelClass::bootHasSystemLogs();

    $instance = new $modelClass;
    $instance->name = 'Disabled';
    $instance->save();

    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBe(0);
});

it('systemLogs returns morph many relationship', function () {
    $model = makeLoggableModel();

    expect($model->systemLogs())->toBeInstanceOf(MorphMany::class);
});

it('systemLogs returns logs belonging to model', function () {
    Queue::fake();

    $modelClass = get_class(makeLoggableModel());

    $instance = new $modelClass;
    $instance->name = 'Relation Test';
    $instance->save();

    app(DeferredCallbackCollection::class)->invoke();

    expect($instance->systemLogs()->count())->toBeGreaterThanOrEqual(1);
});

it('systemLogs uses configured model class', function () {
    config()->set('system-log.database.model', SystemLog::class);

    $model = makeLoggableModel();
    $relation = $model->systemLogs();

    expect($relation->getRelated())->toBeInstanceOf(SystemLog::class);
});
