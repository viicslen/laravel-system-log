<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\DataTransferObjects;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * A lightweight, immutable snapshot of the execution environment at the moment
 * a model event is observed.  Captured synchronously (in the request cycle) so
 * that by the time the deferred job runs, the original context is preserved.
 *
 * @phpstan-immutable
 */
readonly class ExecutionContext
{
    public function __construct(
        /** 'web' | 'cli' | 'job' */
        public string $origin,
        /** Formatted backtrace string, vendor frames stripped */
        public string $trace,
        /** Authenticated user ID at the moment of capture, or null */
        public ?int $userId,
        /** Full request URL, empty string for CLI/job contexts */
        public string $url,
        /** Sanitized request input (sensitive keys removed) */
        public array $input,
    ) {}

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public static function capture(): self
    {
        return new self(
            origin: self::resolveOrigin(),
            trace: self::captureTrace(),
            userId: Auth::id() !== null ? (int) Auth::id() : null,
            url: App::runningInConsole() ? '' : Request::fullUrl(),
            input: App::runningInConsole() ? [] : self::sanitisedInput(),
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function resolveOrigin(): string
    {
        if (App::runningInConsole()) {
            // Distinguish between Artisan commands and queued jobs.
            // Queue workers set 'queue.worker' in the container.
            return app()->bound('queue.worker') ? 'job' : 'cli';
        }

        return 'web';
    }

    private static function captureTrace(): string
    {
        $maxFrames = (int) config('system-log.backtrace.max_frames', 10);

        // Prefer the pre-compiled PCRE patterns written by SystemLogServiceProvider
        // at boot (zero compilation cost per call).  Fall back to the raw glob
        // strings + str_contains when running outside a full Laravel boot (e.g.
        // unit tests that don't register the provider).
        /** @var array<string>|null $compiledSkip */
        $compiledSkip = config('system-log.backtrace.compiled_patterns');

        /** @var array<string>|null $compiledInclude */
        $compiledInclude = config('system-log.backtrace.compiled_include_patterns');

        /** @var array<string> $rawSkip */
        $rawSkip = $compiledSkip === null
            ? config('system-log.backtrace.skip_paths', ['vendor/', 'artisan'])
            : [];

        /** @var array<string> $rawInclude */
        $rawInclude = ($compiledInclude === null && $compiledSkip === null)
            ? config('system-log.backtrace.include_paths', [])
            : [];

        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);

        $filtered = [];
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? '';

            if (self::shouldSkipFrame($file, $compiledSkip, $compiledInclude, $rawSkip, $rawInclude)) {
                continue;
            }

            $class = $frame['class'] ?? '';
            $function = $frame['function'] ?? '';
            $line = $frame['line'] ?? 0;
            $fileShort = $file !== '' ? str_replace(base_path().'/', '', $file) : 'unknown';

            // Format: ClassName::methodName (relative/path/to/file.php:42)
            $signature = $class !== '' ? "{$class}::{$function}" : $function;
            $filtered[] = "{$signature} ({$fileShort}:{$line})";

            if (count($filtered) >= $maxFrames) {
                break;
            }
        }

        return implode("\n", $filtered);
    }

    /**
     * Determine whether a backtrace frame should be excluded from the trace.
     *
     * Logic: skip if the file matches any skip pattern AND does NOT match any
     * include pattern.  Include patterns act as explicit rescues from the skip
     * list — e.g. skip 'vendor/' but include 'vendor/your-company/*'.
     *
     * @param  array<string>|null  $compiledSkip  Pre-compiled PCRE skip patterns (from boot)
     * @param  array<string>|null  $compiledInclude  Pre-compiled PCRE include patterns (from boot)
     * @param  array<string>  $rawSkip  Raw glob skip patterns (fallback for tests)
     * @param  array<string>  $rawInclude  Raw glob include patterns (fallback for tests)
     */
    private static function shouldSkipFrame(
        string $file,
        ?array $compiledSkip,
        ?array $compiledInclude,
        array $rawSkip,
        array $rawInclude,
    ): bool {
        // Determine whether the frame is covered by any skip rule.
        $skipped = false;

        if ($compiledSkip !== null) {
            foreach ($compiledSkip as $pattern) {
                if (preg_match($pattern, $file) === 1) {
                    $skipped = true;
                    break;
                }
            }
        } else {
            foreach ($rawSkip as $path) {
                if (str_contains($file, $path)) {
                    $skipped = true;
                    break;
                }
            }
        }

        if (! $skipped) {
            return false;
        }

        // The frame would be skipped — check whether an include pattern rescues it.
        if ($compiledInclude !== null) {
            // rescued
            if (array_any($compiledInclude, fn ($pattern) => preg_match($pattern, $file) === 1)) {
                return false;
            }
        } elseif (array_any($rawInclude, fn ($path) => str_contains($file, $path))) {
            // rescued
            return false;
        }

        return true;
    }

    private static function sanitisedInput(): array
    {
        /** @var array<string> $except */
        $except = config('system-log.input_except', [
            'password',
            'password_confirmation',
            'current_password',
            '_token',
        ]);

        $input = Request::except($except);

        // Hard cap the input snapshot at ~8 KB to prevent bloat in the metadata
        // column. If the serialized form exceeds that, we store a truncation note.
        $encoded = json_encode($input, JSON_THROW_ON_ERROR);
        if ($encoded !== false && mb_strlen($encoded) > 8192) {
            return ['_truncated' => true, '_reason' => 'Input payload exceeded 8 KB snapshot limit'];
        }

        return $input;
    }
}
