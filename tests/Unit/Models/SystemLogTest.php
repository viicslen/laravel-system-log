<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use ViicSlen\SystemLog\Enums\LogStatus;
use ViicSlen\SystemLog\Models\SystemLog;

it('has correct fillable fields', function () {
    $log = new SystemLog;

    expect($log->getFillable())->toBe([
        'loggable_type',
        'loggable_id',
        'event',
        'attributes',
        'modified',
        'original',
        'origin',
        'trace',
        'user_id',
        'metadata',
        'status',
    ]);
});

it('casts json columns to arrays', function () {
    $log = SystemLog::create([
        'loggable_type' => 'App\\Models\\User',
        'loggable_id' => '1',
        'event' => 'created',
        'attributes' => ['name' => 'John'],
        'modified' => ['name' => 'Jane'],
        'original' => ['name' => 'John'],
        'metadata' => ['url' => 'https://example.com'],
        'status' => LogStatus::Pending,
    ]);

    expect($log->attributes)->toBeArray()
        ->and($log->modified)->toBeArray()
        ->and($log->original)->toBeArray()
        ->and($log->metadata)->toBeArray();
});

it('casts status to LogStatus enum', function () {
    $log = SystemLog::create([
        'loggable_type' => 'App\\Models\\User',
        'loggable_id' => '1',
        'event' => 'created',
        'status' => LogStatus::Pending,
    ]);

    expect($log->status)->toBe(LogStatus::Pending);
});

it('returns configured table name', function () {
    config()->set('system-log.database.table', 'custom_logs');

    $log = new SystemLog;

    expect($log->getTable())->toBe('custom_logs');
});

it('returns default table name when not configured', function () {
    config()->set('system-log.database.table', null);

    $log = new SystemLog;

    expect($log->getTable())->toBe('system_logs');
});

it('returns configured connection name', function () {
    config()->set('system-log.database.connection', 'custom_db');

    $log = new SystemLog;

    expect($log->getConnectionName())->toBe('custom_db');
});

it('returns null connection when not configured', function () {
    config()->set('system-log.database.connection', null);

    $log = new SystemLog;

    expect($log->getConnectionName())->toBeNull();
});

it('scope pending returns only pending logs', function () {
    SystemLog::create(['loggable_type' => 'User', 'loggable_id' => '1', 'event' => 'created', 'status' => LogStatus::Pending]);
    SystemLog::create(['loggable_type' => 'User', 'loggable_id' => '2', 'event' => 'updated', 'status' => LogStatus::Complete]);
    SystemLog::create(['loggable_type' => 'User', 'loggable_id' => '3', 'event' => 'deleted', 'status' => LogStatus::Failed]);

    $pending = SystemLog::pending()->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->status)->toBe(LogStatus::Pending);
});

it('scope complete returns only complete logs', function () {
    SystemLog::create(['loggable_type' => 'User', 'loggable_id' => '1', 'event' => 'created', 'status' => LogStatus::Pending]);
    SystemLog::create(['loggable_type' => 'User', 'loggable_id' => '2', 'event' => 'updated', 'status' => LogStatus::Complete]);

    $complete = SystemLog::complete()->get();

    expect($complete)->toHaveCount(1)
        ->and($complete->first()->status)->toBe(LogStatus::Complete);
});

it('scope failed returns only failed logs', function () {
    SystemLog::create(['loggable_type' => 'User', 'loggable_id' => '1', 'event' => 'created', 'status' => LogStatus::Pending]);
    SystemLog::create(['loggable_type' => 'User', 'loggable_id' => '3', 'event' => 'deleted', 'status' => LogStatus::Failed]);

    $failed = SystemLog::failed()->get();

    expect($failed)->toHaveCount(1)
        ->and($failed->first()->status)->toBe(LogStatus::Failed);
});

it('scope forModel filters by morph type and id', function () {
    $model = new class extends Model
    {
        protected $table = 'test_models';

        public function getMorphClass(): string
        {
            return 'TestModel';
        }
    };
    $model->id = 42;

    SystemLog::create(['loggable_type' => 'TestModel', 'loggable_id' => '42', 'event' => 'created', 'status' => LogStatus::Pending]);
    SystemLog::create(['loggable_type' => 'OtherModel', 'loggable_id' => '42', 'event' => 'created', 'status' => LogStatus::Pending]);
    SystemLog::create(['loggable_type' => 'TestModel', 'loggable_id' => '99', 'event' => 'created', 'status' => LogStatus::Pending]);

    $logs = SystemLog::forModel($model)->get();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->loggable_type)->toBe('TestModel')
        ->and($logs->first()->loggable_id)->toBe('42');
});

it('loggable relationship returns morph to', function () {
    $log = new SystemLog;

    expect($log->loggable())->toBeInstanceOf(MorphTo::class);
});
