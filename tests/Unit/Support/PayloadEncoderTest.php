<?php

declare(strict_types=1);

use ViicSlen\SystemLog\Support\LogOptions;
use ViicSlen\SystemLog\Support\PayloadEncoder;

covers(PayloadEncoder::class, LogOptions::class);

function encoderOptions(array $attributes = [], array $exclude = []): LogOptions
{
    config([
        'system-log.attributes' => ['max_value_length' => 10, 'strategy' => 'truncate', 'columns' => [], 'casts' => [], ...$attributes],
        'system-log.exclude' => $exclude,
    ]);

    return LogOptions::for('');
}

it('stores short values as they are', function () {
    expect(PayloadEncoder::change(encoderOptions(), ['a' => 'x'], ['a' => 'y']))->toBe([['a' => 'y'], ['a' => 'x']]);
});

it('truncates long values by default', function () {
    [$modified, $original] = PayloadEncoder::change(encoderOptions(), ['a' => str_repeat('o', 20)], ['a' => str_repeat('n', 20)]);

    expect($modified['a'])->toBe(str_repeat('n', 10).'…[truncated]')
        ->and($original['a'])->toBe(str_repeat('o', 10).'…[truncated]');
});

it('omits long values but keeps the change, and cannot undo them', function () {
    $options = encoderOptions(['columns' => ['a' => 'omit']]);

    [$modified, $original] = PayloadEncoder::change($options, ['a' => str_repeat('o', 20)], ['a' => str_repeat('n', 20)]);

    expect($modified['a'])->toBe(['$sl' => 'omit'])
        ->and(PayloadEncoder::undo(['a' => str_repeat('n', 20), 'b' => 1], $original))->toBe(['b' => 1]);
});

it('stores only the changed json keys and undoes them', function () {
    $options = encoderOptions(['columns' => ['data' => 'json']]);
    $old = json_encode(['keep' => str_repeat('k', 20), 'change' => 1, 'drop' => true]);
    $new = json_encode(['keep' => str_repeat('k', 20), 'change' => 2, 'add' => 'x']);

    [$modified, $original] = PayloadEncoder::change($options, ['data' => $old], ['data' => $new]);

    expect((array) $modified['data']['set'])->toBe(['change' => 2, 'add' => 'x'])
        ->and($modified['data']['unset'])->toBe(['drop'])
        ->and((array) $original['data']['set'])->toBe(['change' => 1, 'drop' => true])
        ->and($original['data']['unset'])->toBe(['add'])
        ->and(json_decode(PayloadEncoder::undo(['data' => $new], $original)['data'], true))->toBe(json_decode($old, true));
});

it('stores a reverse patch and undoes it', function () {
    $options = encoderOptions(['columns' => ['body' => 'patch']]);
    $old = implode("\n", array_map(fn ($i) => "line {$i}", range(1, 50)));
    $new = str_replace('line 25', 'line twenty-five', $old);

    [$modified, $original] = PayloadEncoder::change($options, ['body' => $old], ['body' => $new]);

    expect($modified['body'])->toBe(['$sl' => 'patch', 'n' => strlen($new), 'h' => hash('crc32b', $new)])
        ->and($original['body']['$sl'])->toBe('patch')
        ->and(PayloadEncoder::undo(['body' => $new], $original)['body'])->toBe($old);
});

it('falls back to the whole old value when the patch is not smaller', function () {
    $options = encoderOptions(['columns' => ['body' => 'patch']]);
    $old = str_repeat('a', 15);

    [, $original] = PayloadEncoder::change($options, ['body' => $old], ['body' => str_repeat('b', 15)]);

    expect($original['body'])->toBe($old);
});

it('picks a strategy by cast type', function () {
    $options = encoderOptions(['casts' => ['array' => 'omit']]);

    [$modified] = PayloadEncoder::change($options, ['data' => '[]'], ['data' => json_encode([str_repeat('x', 20)])], ['data' => 'array']);

    expect($modified['data'])->toBe(['$sl' => 'omit']);
});

it('drops excluded columns', function () {
    expect(PayloadEncoder::change(encoderOptions(exclude: ['views']), ['views' => 1, 'a' => 1], ['views' => 2, 'a' => 2]))
        ->toBe([['a' => 2], ['a' => 1]]);
});

it('keeps both values whole when a long json value changes to or from something it cannot diff', function (?string $old, ?string $new) {
    [$modified, $original] = PayloadEncoder::change(encoderOptions(['columns' => ['data' => 'json']]), ['data' => $old], ['data' => $new]);

    expect($modified['data'])->toBe($new)
        ->and(PayloadEncoder::undo(['data' => $new], $original))->toBe(['data' => $old]);
})->with([
    'json to null' => ['{"long":"'.str_repeat('l', 20).'"}', null],
    'null to json' => [null, '{"long":"'.str_repeat('l', 20).'"}'],
    'json to a scalar string' => ['{"long":"'.str_repeat('l', 20).'"}', str_repeat('s', 20)],
]);
