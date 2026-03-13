<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use ViicSlen\SystemLog\DTO\ExecutionContext;
use ViicSlen\SystemLog\Enums\LogStatus;
use ViicSlen\SystemLog\Jobs\ProcessLogContext;
use ViicSlen\SystemLog\Models\SystemLog;

covers(ProcessLogContext::class);

it('updates log with context data on handle', function () {
    Queue::fake();

    $log = SystemLog::create([
        'loggable_type' => 'App\\Models\\User',
        'loggable_id' => '1',
        'event' => 'created',
        'status' => LogStatus::Pending,
    ]);

    $context = new ExecutionContext('web', 'some trace', 42, 'https://example.com');
    $job = new ProcessLogContext($log->id, $context);
    $job->handle();

    $log->refresh();

    expect($log->status)->toBe(LogStatus::Complete)
        ->and($log->origin)->toBe('web')
        ->and($log->trace)->toBe('some trace')
        ->and($log->user_id)->toBe(42)
        ->and($log->metadata)->toBe(['url' => 'https://example.com']);
});

it('does nothing when log is not found', function () {
    Queue::fake();

    $context = new ExecutionContext('web', '', null, '');
    $job = new ProcessLogContext(99999, $context);

    // Should not throw
    $job->handle();

    expect(SystemLog::count())->toBe(0);
});

it('marks log as failed when job fails and log is still pending', function () {
    Queue::fake();

    $log = SystemLog::create([
        'loggable_type' => 'App\\Models\\User',
        'loggable_id' => '1',
        'event' => 'created',
        'status' => LogStatus::Pending,
    ]);

    $context = new ExecutionContext('web', '', null, '');
    $job = new ProcessLogContext($log->id, $context);
    $job->failed(new RuntimeException('Queue error'));

    $log->refresh();

    expect($log->status)->toBe(LogStatus::Failed);
});

it('does not change status on failed when log is already complete', function () {
    Queue::fake();

    $log = SystemLog::create([
        'loggable_type' => 'App\\Models\\User',
        'loggable_id' => '1',
        'event' => 'created',
        'status' => LogStatus::Complete,
    ]);

    $context = new ExecutionContext('web', '', null, '');
    $job = new ProcessLogContext($log->id, $context);
    $job->failed(new RuntimeException('Queue error'));

    $log->refresh();

    // Only pending -> failed; complete logs are left untouched
    expect($log->status)->toBe(LogStatus::Complete);
});

it('has correct retry configuration', function () {
    $context = new ExecutionContext('web', '', null, '');
    $job = new ProcessLogContext(1, $context);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(10);
});

it('uses configured model class from config', function () {
    Queue::fake();

    config()->set('system-log.database.model', SystemLog::class);

    $log = SystemLog::create([
        'loggable_type' => 'App\\Models\\User',
        'loggable_id' => '1',
        'event' => 'created',
        'status' => LogStatus::Pending,
    ]);

    $context = new ExecutionContext('cli', 'trace here', null, '');
    $job = new ProcessLogContext($log->id, $context);
    $job->handle();

    $log->refresh();

    expect($log->status)->toBe(LogStatus::Complete)
        ->and($log->origin)->toBe('cli');
});
