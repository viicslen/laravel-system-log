<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Queue;
use ViicSlen\SystemLog\Contracts\ScopeBridge;
use ViicSlen\SystemLog\Enums\LogStatus;
use ViicSlen\SystemLog\Jobs\ProcessLogContext;
use ViicSlen\SystemLog\Models\SystemLog;
use ViicSlen\SystemLog\Observers\ModelActivityObserver;

covers(ModelActivityObserver::class);

// Records bridge interactions and wraps the deferred write so the test can
// assert the observer captured scope synchronously and restored it around the
// write.
class RecordingScopeBridge implements ScopeBridge
{
    public static bool $captured = false;

    public static bool $restored = false;

    public static mixed $scope = null;

    public static function reset(): void
    {
        self::$captured = false;
        self::$restored = false;
        self::$scope = null;
    }

    public function capture(): mixed
    {
        self::$captured = true;

        return 'tenant-123';
    }

    public function restore(mixed $scope, Closure $callback): void
    {
        self::$restored = true;
        self::$scope = $scope;

        $callback();
    }
}

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

it('captures scope synchronously and restores it around the deferred write', function () {
    Queue::fake();
    RecordingScopeBridge::reset();
    config(['system-log.scope' => RecordingScopeBridge::class]);

    $observer = new ModelActivityObserver;
    $model = makeTestModel(['name' => 'Heidi']);
    $model->id = 7;

    $observer->created($model);

    // capture() runs synchronously, before the deferred write is flushed.
    expect(RecordingScopeBridge::$captured)->toBeTrue()
        ->and(RecordingScopeBridge::$restored)->toBeFalse();

    app(DeferredCallbackCollection::class)->invoke();

    // restore() wrapped the write, receiving the captured token, and the write
    // still produced the log row.
    expect(RecordingScopeBridge::$restored)->toBeTrue()
        ->and(RecordingScopeBridge::$scope)->toBe('tenant-123')
        ->and(SystemLog::count())->toBe(1);
});

it('writes inline when no scope bridge is configured', function () {
    Queue::fake();
    config(['system-log.scope' => null]);

    $observer = new ModelActivityObserver;
    $model = makeTestModel(['name' => 'Ivan']);
    $model->id = 8;

    $observer->created($model);

    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBe(1);
});

it('truncates payload string values longer than the configured max length', function () {
    Queue::fake();
    config(['system-log.attributes.max_value_length' => 10]);

    $observer = new ModelActivityObserver;
    $model = makeTestModel(['name' => str_repeat('a', 50)]);
    $model->id = 9;

    $observer->created($model);

    app(DeferredCallbackCollection::class)->invoke();

    $log = SystemLog::first();

    expect($log->attributes['name'])->toBe(str_repeat('a', 10).'…[truncated]');
});

it('leaves short values and non-strings untouched, and disables truncation when null', function () {
    Queue::fake();
    config(['system-log.attributes.max_value_length' => null]);

    $observer = new ModelActivityObserver;
    $long = str_repeat('b', 100);
    $model = makeTestModel(['name' => $long, 'id' => 12345]);
    $model->id = 10;

    $observer->created($model);

    app(DeferredCallbackCollection::class)->invoke();

    $log = SystemLog::first();

    // null length stores the full value; the integer id is never truncated.
    expect($log->attributes['name'])->toBe($long)
        ->and($log->attributes['id'])->toBe(10);
});
