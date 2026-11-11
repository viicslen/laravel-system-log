<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Support;

/**
 * Turns attribute values into what a log row stores, and undoes a stored row against a state.
 *
 * Values longer than `max_value_length` go through the column's strategy. Strategies that do not
 * store the value itself leave an envelope, an array keyed by {@see self::MARKER}; raw attribute
 * values are never arrays, so an array always means an envelope:
 *
 * - `truncate`: the value cut to the limit, with a "…[truncated]" marker (lossy).
 * - `omit`: `{"$sl": "omit"}` — the change is recorded, the value is not.
 * - `json`: `{"$sl": "json", "set": {...}, "unset": [...]}` — only the top-level keys that
 *   changed, with their values on that side and the keys absent on that side.
 * - `patch`: `original` holds `{"$sl": "patch", "patch": ...}` turning the new value back into the
 *   old one (or the whole old value when that is not smaller); `modified` holds `{"$sl": "patch"}`.
 */
final class PayloadEncoder
{
    public const string MARKER = '$sl';

    public const string TRUNCATE = 'truncate';

    public const string OMIT = 'omit';

    public const string JSON = 'json';

    public const string PATCH = 'patch';

    private const string TRUNCATED = '…[truncated]';

    /**
     * Attributes stored as a full snapshot (created / deleted / full-mode updates).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, string>  $casts
     * @return array<string, mixed>
     */
    public static function snapshot(LogOptions $options, array $attributes, array $casts = []): array
    {
        $snapshot = [];

        foreach (self::withoutExcluded($options, $attributes) as $column => $value) {
            $snapshot[$column] = match (self::strategy($options, $column, $casts, $value)) {
                self::TRUNCATE => self::truncate($value, $options->maxValueLength),
                self::OMIT => self::envelope(self::OMIT),
                // A snapshot has no other side to diff against, so these keep the value whole.
                default => $value,
            };
        }

        return $snapshot;
    }

    /**
     * The `modified` / `original` pair for a change from `$old` to `$new`, over the columns in `$new`.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, string>  $casts
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function change(LogOptions $options, array $old, array $new, array $casts = []): array
    {
        $modified = [];
        $original = [];

        foreach (self::withoutExcluded($options, $new) as $column => $newValue) {
            $oldValue = $old[$column] ?? null;
            [$modified[$column], $original[$column]] = match (self::strategy($options, $column, $casts, $oldValue, $newValue)) {
                self::TRUNCATE => [self::truncate($newValue, $options->maxValueLength), self::truncate($oldValue, $options->maxValueLength)],
                self::OMIT => [self::omitIfLong($newValue, $options), self::omitIfLong($oldValue, $options)],
                self::JSON => self::jsonChange($oldValue, $newValue, $options),
                self::PATCH => self::patchChange($oldValue, $newValue, $options),
                default => [$newValue, $oldValue],
            };
        }

        return [$modified, $original];
    }

    /**
     * The state before a row, given the state after it and the row's stored `original`.
     *
     * A column whose earlier value cannot be recovered (omitted, or a patch that no longer fits
     * the value it is applied to) is removed from the state rather than guessed.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $original
     * @return array<string, mixed>
     */
    public static function undo(array $state, array $original): array
    {
        foreach ($original as $column => $value) {
            if (! self::isEnvelope($value)) {
                $state[$column] = $value;

                continue;
            }

            $current = $state[$column] ?? null;
            $restored = match ($value[self::MARKER]) {
                self::PATCH => is_string($current) && isset($value['patch']) ? LinePatch::apply($current, $value['patch']) : null,
                self::JSON => self::applyJson($current, $value),
                default => null,
            };

            if ($restored === null) {
                unset($state[$column]);
            } else {
                $state[$column] = $restored;
            }
        }

        return $state;
    }

    public static function isEnvelope(mixed $value): bool
    {
        return is_array($value) && isset($value[self::MARKER]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function withoutExcluded(LogOptions $options, array $attributes): array
    {
        return array_diff_key($attributes, array_flip($options->exclude));
    }

    /**
     * @param  array<string, string>  $casts
     */
    private static function strategy(LogOptions $options, string $column, array $casts, mixed ...$values): string
    {
        $isLong = $options->maxValueLength !== null
            && array_filter($values, static fn (mixed $value): bool => self::isLong($value, (int) $options->maxValueLength)) !== [];

        if (! $isLong) {
            return 'plain';
        }

        return $options->columnStrategies[$column]
            ?? (isset($casts[$column]) ? ($options->castStrategies[$casts[$column]] ?? null) : null)
            ?? $options->strategy;
    }

    private static function isLong(mixed $value, int $max): bool
    {
        if (is_array($value)) {
            return array_filter($value, static fn (mixed $item): bool => self::isLong($item, $max)) !== [];
        }

        return is_string($value) && mb_strlen($value) > $max;
    }

    private static function truncate(mixed $value, ?int $max): mixed
    {
        if ($max === null || $max <= 0) {
            return $value;
        }

        // Cast values (full mode's `original`) can nest strings in arrays.
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::truncate($item, $max), $value);
        }

        if (! is_string($value) || mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max).self::TRUNCATED;
    }

    private static function omitIfLong(mixed $value, LogOptions $options): mixed
    {
        return is_string($value) && strlen($value) > (int) $options->maxValueLength ? self::envelope(self::OMIT) : $value;
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private static function jsonChange(mixed $old, mixed $new, LogOptions $options): array
    {
        $oldArray = is_string($old) ? json_decode($old, true) : null;
        $newArray = is_string($new) ? json_decode($new, true) : null;

        if (! is_array($oldArray) || ! is_array($newArray)) {
            return [self::truncate($new, $options->maxValueLength), self::truncate($old, $options->maxValueLength)];
        }

        $changed = array_keys(array_filter(
            $oldArray + $newArray,
            static fn (mixed $_, int|string $key): bool => ! array_key_exists($key, $oldArray)
                || ! array_key_exists($key, $newArray)
                || $oldArray[$key] !== $newArray[$key],
            ARRAY_FILTER_USE_BOTH,
        ));

        return [self::jsonSide($newArray, $changed), self::jsonSide($oldArray, $changed)];
    }

    /**
     * @param  array<array-key, mixed>  $side
     * @param  list<array-key>  $keys
     * @return array<string, mixed>
     */
    private static function jsonSide(array $side, array $keys): array
    {
        $present = array_values(array_filter($keys, static fn (int|string $key): bool => array_key_exists($key, $side)));

        return self::envelope(self::JSON) + [
            'set' => (object) array_intersect_key($side, array_flip($present)),
            'unset' => array_values(array_diff($keys, $present)),
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private static function applyJson(mixed $current, array $envelope): ?string
    {
        $decoded = is_string($current) ? json_decode($current, true) : null;

        if (! is_array($decoded)) {
            return null;
        }

        foreach ((array) ($envelope['set'] ?? []) as $key => $value) {
            $decoded[$key] = $value;
        }

        foreach ((array) ($envelope['unset'] ?? []) as $key) {
            unset($decoded[$key]);
        }

        return json_encode($decoded) ?: null;
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private static function patchChange(mixed $old, mixed $new, LogOptions $options): array
    {
        if (! is_string($new) || strlen($new) <= (int) $options->maxValueLength) {
            return [$new, $old];
        }

        if (! is_string($old)) {
            return [self::envelope(self::PATCH), $old];
        }

        $patch = LinePatch::make($new, $old);
        $encoded = self::envelope(self::PATCH) + ['patch' => $patch];

        return [
            self::envelope(self::PATCH),
            strlen((string) json_encode($encoded)) < strlen($old) ? $encoded : $old,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function envelope(string $strategy): array
    {
        return [self::MARKER => $strategy];
    }
}
