<?php

declare(strict_types=1);

use ViicSlen\SystemLog\Models\SystemLog;
use ViicSlen\SystemLog\SystemLogServiceProvider;

covers(SystemLogServiceProvider::class);

it('registers the system-log config', function () {
    expect(config('system-log'))->toBeArray()
        ->and(config('system-log.enabled'))->not->toBeNull();
});

it('binds SystemLog model to container', function () {
    $resolved = app(SystemLog::class);

    expect($resolved)->toBeInstanceOf(SystemLog::class);
});

it('binds configured model class to container', function () {
    config()->set('system-log.database.model', SystemLog::class);

    // Re-boot the provider to pick up the new config
    $provider = new SystemLogServiceProvider(app());
    $provider->packageBooted();

    $resolved = app(SystemLog::class);

    expect($resolved)->toBeInstanceOf(SystemLog::class);
});

it('compiles skip_paths glob patterns into PCRE on boot', function () {
    $compiled = config('system-log.backtrace.compiled_patterns');

    expect($compiled)->toBeArray()
        ->and($compiled)->not->toBeEmpty();

    // Each entry should be a valid PCRE pattern
    foreach ($compiled as $pattern) {
        expect(@preg_match($pattern, ''))->not->toBeFalse();
    }
});

it('compiles include_paths glob patterns into PCRE on boot', function () {
    config()->set('system-log.backtrace.include_paths', ['vendor/my-company/']);

    $provider = new SystemLogServiceProvider(app());
    $provider->packageBooted();

    $compiled = config('system-log.backtrace.compiled_include_patterns');

    expect($compiled)->toBeArray()
        ->and($compiled)->not->toBeEmpty();
});

it('compiled patterns are valid PCRE regex', function () {
    $patterns = config('system-log.backtrace.compiled_patterns');

    foreach ($patterns as $pattern) {
        // preg_match returns false on invalid pattern
        $result = preg_match($pattern, 'test/path/to/file.php');
        expect($result)->not->toBeFalse();
    }
});

it('glob wildcards are correctly converted to PCRE', function () {
    config()->set('system-log.backtrace.skip_paths', ['vendor/*', 'app/?.php']);

    $provider = new SystemLogServiceProvider(app());
    $provider->packageBooted();

    $compiled = config('system-log.backtrace.compiled_patterns');

    expect($compiled)->toHaveCount(2);

    // vendor/* should match vendor/laravel/framework
    expect(preg_match($compiled[0], 'vendor/laravel/framework'))->toBe(1);
    // app/?.php should match app/a.php but not app/ab.php (? = single char)
    expect(preg_match($compiled[1], 'app/a.php'))->toBe(1);
});

it('empty include_paths results in empty compiled_include_patterns', function () {
    config()->set('system-log.backtrace.include_paths', []);

    $provider = new SystemLogServiceProvider(app());
    $provider->packageBooted();

    $compiled = config('system-log.backtrace.compiled_include_patterns');

    expect($compiled)->toBeArray()->toBeEmpty();
});
