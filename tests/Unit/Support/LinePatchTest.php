<?php

declare(strict_types=1);

use ViicSlen\SystemLog\Support\LinePatch;

covers(LinePatch::class);

it('turns the source back into the target', function (string $from, string $to) {
    expect(LinePatch::apply($from, LinePatch::make($from, $to)))->toBe($to);
})->with([
    'identical' => ['same', 'same'],
    'one line edited' => ["a\nb\nc\n", "a\nB\nc\n"],
    'lines added and removed' => ["a\nb\nc\nd\n", "a\nx\ny\nc\n"],
    'scattered edits' => ["1\n2\n3\n4\n5\n6\n", "1\ntwo\n3\n4\nfive\n6\nseven\n"],
    'to empty' => ["a\nb\n", ''],
    'from empty' => ['', "a\nb\n"],
    'no trailing newline' => ["a\nb", "a\nc"],
    'multibyte' => ["café\nnaïve\n", "cafe\nnaïve!\n"],
]);

it('narrows an edit inside one long line to the bytes that differ', function () {
    $from = '<div>'.str_repeat('x', 5000).'<b>old</b>'.str_repeat('y', 5000).'</div>';
    $to = str_replace('old', 'new', $from);

    $patch = LinePatch::make($from, $to);

    expect($patch['hunks'])->toBe([[5008, 3, 'new']])
        ->and(LinePatch::apply($from, $patch))->toBe($to);
});

it('refuses a source the patch was not made from', function () {
    $patch = LinePatch::make("a\nb\n", "a\nc\n");

    expect(LinePatch::apply("a\nz\n", $patch))->toBeNull();
});
