<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Queue;
use ViicSlen\SystemLog\Concerns\HasSystemLogs;
use ViicSlen\SystemLog\Enums\LogStatus;
use ViicSlen\SystemLog\Jobs\ProcessLogContext;
use ViicSlen\SystemLog\Models\SystemLog;

// A concrete loggable model used across feature tests
class TestLoggableModel extends Model
{
    use HasSystemLogs;

    protected $table = 'test_models';

    protected $fillable = ['name', 'email'];
}

beforeEach(function () {
    // Clear observer registrations between tests to avoid cross-test pollution
    TestLoggableModel::flushEventListeners();
    TestLoggableModel::bootHasSystemLogs();
});

it('creates a system_logs row when a model is created', function () {
    Queue::fake();

    $model = TestLoggableModel::create(['name' => 'Alice', 'email' => 'alice@example.com']);

    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBe(1);

    $log = SystemLog::first();
    expect($log->event)->toBe('created')
        ->and($log->loggable_type)->toBe($model->getMorphClass())
        ->and($log->loggable_id)->toBe((string) $model->id)
        ->and($log->status)->toBe(LogStatus::Pending)
        ->and($log->attributes)->toBeArray()
        ->and($log->attributes)->toHaveKey('name');
});

it('creates a system_logs row when a model is updated', function () {
    Queue::fake();

    $model = TestLoggableModel::create(['name' => 'Bob', 'email' => 'bob@example.com']);

    app(DeferredCallbackCollection::class)->invoke();
    SystemLog::query()->delete();

    $model->update(['name' => 'Robert']);

    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBe(1);

    $log = SystemLog::first();
    expect($log->event)->toBe('updated')
        ->and($log->modified)->toHaveKey('name')
        ->and($log->original)->toHaveKey('name')
        ->and($log->original['name'])->toBe('Bob');
});

it('creates a system_logs row when a model is deleted', function () {
    Queue::fake();

    $model = TestLoggableModel::create(['name' => 'Charlie']);

    app(DeferredCallbackCollection::class)->invoke();
    SystemLog::query()->delete();

    $model->delete();

    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBe(1);

    $log = SystemLog::first();
    expect($log->event)->toBe('deleted')
        ->and($log->attributes)->toHaveKey('name');
});

it('does not log when only timestamps change on update', function () {
    Queue::fake();

    $model = TestLoggableModel::create(['name' => 'Dave']);

    app(DeferredCallbackCollection::class)->invoke();
    SystemLog::query()->delete();

    // touch() updates updated_at but getChanges() returns only DB-synced changes
    // A no-change update returns empty changes array
    $model->save(); // saving without modifications

    app(DeferredCallbackCollection::class)->invoke();

    // No new log should be created as there are no dirty attributes
    expect(SystemLog::count())->toBe(0);
});

it('dispatches ProcessLogContext job with correct queue connection', function () {
    Queue::fake();

    config()->set('system-log.queue.connection', 'sync');
    config()->set('system-log.queue.name', 'default');

    TestLoggableModel::create(['name' => 'Eve']);

    app(DeferredCallbackCollection::class)->invoke();

    Queue::assertPushed(ProcessLogContext::class);
});

it('ProcessLogContext job enriches log with complete status', function () {
    // Use sync queue so job runs immediately
    config()->set('queue.default', 'sync');

    $model = TestLoggableModel::create(['name' => 'Frank']);

    app(DeferredCallbackCollection::class)->invoke();

    $log = SystemLog::first();
    expect($log->status)->toBe(LogStatus::Complete);
});

it('can retrieve systemLogs via relationship', function () {
    Queue::fake();

    $model = TestLoggableModel::create(['name' => 'Grace']);

    app(DeferredCallbackCollection::class)->invoke();

    expect($model->systemLogs()->count())->toBe(1);
});

it('log is not created when system-log is disabled', function () {
    Queue::fake();

    config()->set('system-log.enabled', false);

    // Re-flush listeners to pick up disabled config
    TestLoggableModel::flushEventListeners();

    $model = TestLoggableModel::create(['name' => 'Disabled User']);

    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBe(0);
});

it('multiple model creates produce separate log entries', function () {
    Queue::fake();

    TestLoggableModel::create(['name' => 'Harry']);
    TestLoggableModel::create(['name' => 'Ida']);
    TestLoggableModel::create(['name' => 'Jack']);

    app(DeferredCallbackCollection::class)->invoke();

    expect(SystemLog::count())->toBe(3);
});
