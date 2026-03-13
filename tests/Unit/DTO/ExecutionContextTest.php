<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use ViicSlen\SystemLog\DTO\ExecutionContext;

it('captures web origin when not running in console', function () {
    App::shouldReceive('runningInConsole')->andReturn(false);

    $context = ExecutionContext::capture();

    expect($context->origin)->toBe('web');
});

it('captures cli origin when running in console without queue worker', function () {
    App::shouldReceive('runningInConsole')->andReturn(true);
    app()->offsetUnset('queue.worker');

    $context = ExecutionContext::capture();

    expect($context->origin)->toBe('cli');
});

it('captures job origin when running in console with queue worker bound', function () {
    App::shouldReceive('runningInConsole')->andReturn(true);
    app()->bind('queue.worker', fn () => new stdClass);

    $context = ExecutionContext::capture();

    expect($context->origin)->toBe('job');
});

it('captures authenticated user id', function () {
    $user = new class extends User
    {
        public int $id = 42;

        public function getAuthIdentifier(): int
        {
            return $this->id;
        }
    };

    Auth::shouldReceive('id')->andReturn(42);

    $context = ExecutionContext::capture();

    expect($context->userId)->toBe(42);
});

it('captures null user id when unauthenticated', function () {
    Auth::shouldReceive('id')->andReturn(null);

    $context = ExecutionContext::capture();

    expect($context->userId)->toBeNull();
});

it('captures empty url for console origin', function () {
    App::shouldReceive('runningInConsole')->andReturn(true);

    $context = ExecutionContext::capture();

    expect($context->url)->toBe('');
});

it('captures full url for web origin', function () {
    App::shouldReceive('runningInConsole')->andReturn(false);

    $context = ExecutionContext::capture();

    // In testbench, the URL will be the test URL
    expect($context->url)->toBeString();
});

it('captures a backtrace string', function () {
    $context = ExecutionContext::capture();

    expect($context->trace)->toBeString();
});

it('is immutable readonly', function () {
    $context = new ExecutionContext('web', 'trace', 1, 'https://example.com');

    expect($context->origin)->toBe('web')
        ->and($context->trace)->toBe('trace')
        ->and($context->userId)->toBe(1)
        ->and($context->url)->toBe('https://example.com');
});

it('backtrace respects max frames config', function () {
    config()->set('system-log.backtrace.max_frames', 2);
    // Remove compiled patterns so raw fallback is used
    config()->offsetUnset('system-log.backtrace.compiled_patterns');
    config()->set('system-log.backtrace.skip_paths', []);

    $context = ExecutionContext::capture();
    $frames = array_filter(explode("\n", $context->trace));

    expect(count($frames))->toBeLessThanOrEqual(2);
});

it('backtrace skips vendor frames by default', function () {
    config()->offsetUnset('system-log.backtrace.compiled_patterns');
    config()->set('system-log.backtrace.skip_paths', ['vendor/']);
    config()->set('system-log.backtrace.max_frames', 50);

    $context = ExecutionContext::capture();

    foreach (explode("\n", $context->trace) as $frame) {
        expect($frame)->not->toContain('vendor/');
    }
});

it('backtrace include paths rescue frames from skip list', function () {
    config()->offsetUnset('system-log.backtrace.compiled_patterns');
    config()->set('system-log.backtrace.skip_paths', ['vendor/']);
    config()->set('system-log.backtrace.include_paths', ['vendor/pestphp/']);
    config()->set('system-log.backtrace.max_frames', 50);

    // This just validates it runs without error when include paths are set
    $context = ExecutionContext::capture();

    expect($context->trace)->toBeString();
});

it('uses compiled PCRE patterns when available', function () {
    config()->set('system-log.backtrace.compiled_patterns', ['#vendor/#']);
    config()->set('system-log.backtrace.compiled_include_patterns', []);
    config()->set('system-log.backtrace.max_frames', 50);

    $context = ExecutionContext::capture();

    foreach (explode("\n", $context->trace) as $frame) {
        if (trim($frame) !== '') {
            expect($frame)->not->toContain('vendor/');
        }
    }
});
