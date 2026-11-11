<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Support;

/**
 * A reverse patch that turns one string back into another.
 *
 * Changed regions are found with a line diff, then narrowed to the bytes that differ, so an edit
 * inside one long line (single-line HTML) still yields a small patch. Offsets are byte offsets
 * into the source string; each hunk replaces `length` bytes at `start` with `text`.
 *
 * @phpstan-type Patch array{n: int, h: string, hunks: list<array{0: int, 1: int, 2: string}>}
 */
final class LinePatch
{
    /** Line pairs above which the middle is replaced as one hunk instead of diffed. */
    private const int MAX_LCS_CELLS = 250_000;

    /**
     * Patch that turns `$from` into `$to`.
     *
     * @return Patch
     */
    public static function make(string $from, string $to): array
    {
        return [
            'n' => strlen($from),
            'h' => hash('crc32b', $from),
            'hunks' => self::hunks($from, $to),
        ];
    }

    /**
     * Apply a patch made by {@see self::make()}, or null when `$from` is not the string it was
     * made from (a write that bypassed the log changed it).
     *
     * @param  Patch  $patch
     */
    public static function apply(string $from, array $patch): ?string
    {
        if (strlen($from) !== $patch['n'] || hash('crc32b', $from) !== $patch['h']) {
            return null;
        }

        foreach (array_reverse($patch['hunks']) as [$start, $length, $text]) {
            $from = substr_replace($from, $text, $start, $length);
        }

        return $from;
    }

    /**
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private static function hunks(string $from, string $to): array
    {
        if ($from === $to) {
            return [];
        }

        $a = self::lines($from);
        $b = self::lines($to);

        $prefix = 0;
        $max = min(count($a), count($b));

        while ($prefix < $max && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;

        while ($suffix < $max - $prefix && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) {
            $suffix++;
        }

        $midA = array_slice($a, $prefix, count($a) - $prefix - $suffix);
        $midB = array_slice($b, $prefix, count($b) - $prefix - $suffix);
        $offset = strlen(implode('', array_slice($a, 0, $prefix)));

        $lineHunks = count($midA) * count($midB) <= self::MAX_LCS_CELLS
            ? self::lcsHunks($midA, $midB)
            : [[0, count($midA), 0, count($midB)]];

        $hunks = [];

        foreach ($lineHunks as [$aStart, $aLength, $bStart, $bLength]) {
            $start = $offset + strlen(implode('', array_slice($midA, 0, $aStart)));
            $old = implode('', array_slice($midA, $aStart, $aLength));
            $new = implode('', array_slice($midB, $bStart, $bLength));

            $hunks[] = self::narrow($start, $old, $new);
        }

        return $hunks;
    }

    /**
     * Shrink a hunk to the bytes that differ between its two sides.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private static function narrow(int $start, string $old, string $new): array
    {
        $head = 0;
        $max = min(strlen($old), strlen($new));

        while ($head < $max && $old[$head] === $new[$head]) {
            $head++;
        }

        $tail = 0;

        while ($tail < $max - $head && $old[strlen($old) - 1 - $tail] === $new[strlen($new) - 1 - $tail]) {
            $tail++;
        }

        return [$start + $head, strlen($old) - $head - $tail, substr($new, $head, strlen($new) - $head - $tail)];
    }

    /**
     * Changed line ranges between two line lists, from their longest common subsequence.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: int, 1: int, 2: int, 3: int}> [aStart, aLength, bStart, bLength]
     */
    private static function lcsHunks(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $table[$i][$j] = $a[$i] === $b[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        $hunks = [];
        $i = $j = 0;
        $open = null;

        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                if ($open !== null) {
                    $hunks[] = [$open[0], $i - $open[0], $open[1], $j - $open[1]];
                    $open = null;
                }

                $i++;
                $j++;

                continue;
            }

            $open ??= [$i, $j];

            if ($j < $m && ($i >= $n || $table[$i][$j + 1] >= $table[$i + 1][$j])) {
                $j++;
            } else {
                $i++;
            }
        }

        if ($open !== null) {
            $hunks[] = [$open[0], $n - $open[0], $open[1], $m - $open[1]];
        }

        return $hunks;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $value): array
    {
        return preg_split('/(?<=\n)/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
