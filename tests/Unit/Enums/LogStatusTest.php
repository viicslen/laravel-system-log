<?php

declare(strict_types=1);

use ViicSlen\SystemLog\Enums\LogStatus;

covers(LogStatus::class);

it('has correct string values', function () {
    expect(LogStatus::Pending->value)->toBe('pending')
        ->and(LogStatus::Complete->value)->toBe('complete')
        ->and(LogStatus::Failed->value)->toBe('failed');
});

it('can be created from string value', function () {
    expect(LogStatus::from('pending'))->toBe(LogStatus::Pending)
        ->and(LogStatus::from('complete'))->toBe(LogStatus::Complete)
        ->and(LogStatus::from('failed'))->toBe(LogStatus::Failed);
});

it('tryFrom returns null for invalid value', function () {
    expect(LogStatus::tryFrom('invalid'))->toBeNull();
});

it('cases returns all enum values', function () {
    $cases = LogStatus::cases();

    expect($cases)->toHaveCount(3)
        ->and($cases)->toContain(LogStatus::Pending)
        ->and($cases)->toContain(LogStatus::Complete)
        ->and($cases)->toContain(LogStatus::Failed);
});
