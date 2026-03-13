<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Queue;
use ViicSlen\SystemLog\Enums\LogStatus;
use ViicSlen\SystemLog\Jobs\ProcessLogContext;
use ViicSlen\SystemLog\Models\SystemLog;
use ViicSlen\SystemLog\Observers\ModelActivityObserver;

covers(ModelActivityObserver::class);

// Helper to create an anonymous model for testing
function makeTestModel(array $attributes = []): Model
{
    $model = new class extends Model
    {
        protected $table = 'test_models';

        protected $fillable = ['name', 'email'];
    };

    foreach ($attributes as $key => $value) {
        $model->setAttribute($key, $value);
    }

    return $model;
}

it('creates a log entry on model created event', function () {
    Queue::fake();

    $observer = new ModelActivityObserver;
    $model = makeTestModel(['name' => 'Alice']);
    $model->id = 1;

    $observer->created($model);

    // Flush deferred closures
    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBe(1);

    $log = SystemLog::first();
    expect($log->event)->toBe('created')
        ->and($log->status)->toBe(LogStatus::Pending)
        ->and($log->attributes)->toBeArray();
});

it('creates a log entry on model deleted event', function () {
    Queue::fake();

    $observer = new ModelActivityObserver;
    $model = makeTestModel(['name' => 'Bob']);
    $model->id = 2;

    $observer->deleted($model);

    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBe(1);

    $log = SystemLog::first();
    expect($log->event)->toBe('deleted')
        ->and($log->attributes)->toBeArray();
});

it('creates a log entry on model updated event with changes', function () {
    Queue::fake();

    $observer = new ModelActivityObserver;
    $model = makeTestModel(['name' => 'Charlie', 'email' => 'charlie@example.com']);
    $model->id = 3;

    // Simulate Eloquent changes
    $model->syncOriginal();
    $model->setAttribute('name', 'Charles');
    $model->syncChanges();

    $observer->updated($model);

    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBe(1);

    $log = SystemLog::first();
    expect($log->event)->toBe('updated')
        ->and($log->modified)->toBeArray()
        ->and($log->original)->toBeArray();
});

it('skips creating a log when model updated has no changes', function () {
    Queue::fake();

    $observer = new ModelActivityObserver;
    $model = makeTestModel(['name' => 'Dave']);
    $model->id = 4;

    // No changes applied — getChanges() returns []
    $observer->updated($model);

    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBe(0);
});

it('dispatches ProcessLogContext job after creating log', function () {
    Queue::fake();

    $observer = new ModelActivityObserver;
    $model = makeTestModel(['name' => 'Eve']);
    $model->id = 5;

    $observer->created($model);

    app(DeferredCallbackCollection::class)->invoke();

    Queue::assertPushed(ProcessLogContext::class);
});

it('stores correct loggable type and id', function () {
    Queue::fake();

    $observer = new ModelActivityObserver;
    $model = makeTestModel(['name' => 'Frank']);
    $model->id = 99;

    $observer->created($model);

    app(DeferredCallbackCollection::class)->invoke();

    $log = SystemLog::first();
    expect($log->loggable_id)->toBe('99')
        ->and($log->loggable_type)->not->toBeEmpty();
});

it('stores only changed keys in original for updated event', function () {
    Queue::fake();

    $observer = new ModelActivityObserver;
    $model = makeTestModel(['name' => 'Grace', 'email' => 'grace@example.com']);
    $model->id = 6;
    $model->syncOriginal();
    $model->setAttribute('name', 'Gracie');
    $model->syncChanges();

    $observer->updated($model);

    app(DeferredCallbackCollection::class)->invoke();

    $log = SystemLog::first();
    // original should only contain the keys that changed
    expect($log->original)->toHaveKey('name')
        ->and($log->original)->not->toHaveKey('email');
});
