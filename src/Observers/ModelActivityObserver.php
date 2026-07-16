<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Observers;

use Illuminate\Database\Eloquent\Model;
use ViicSlen\SystemLog\Contracts\ScopeBridge;
use ViicSlen\SystemLog\DTO\ExecutionContext;
use ViicSlen\SystemLog\Enums\LogStatus;
use ViicSlen\SystemLog\Jobs\ProcessLogContext;
use ViicSlen\SystemLog\Models\SystemLog;

class ModelActivityObserver
{
    // -------------------------------------------------------------------------
    // Event Handlers
    // -------------------------------------------------------------------------

    public function created(Model $model): void
    {
        // Snapshot the full attribute set of the newly created model.
        $this->log($model, 'created', attributes: $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        // getChanges() returns only the columns that actually changed.
        // Skip entirely if Eloquent fired the event with no real changes
        // (e.g. touch() calls that only update timestamps).
        $changes = $model->getChanges();

        if (empty($changes)) {
            return;
        }

        // Pair the new values with the originals so that each changed column
        // can be diffed in a single query on the log record.
        $this->log($model, 'updated',
            attributes: $model->getAttributes(),
            modified: $changes,
            original: array_intersect_key($model->getOriginal(), $changes),
        );
    }

    public function deleted(Model $model): void
    {
        // Capture the full attribute snapshot before the row disappears.
        $this->log($model, 'deleted', attributes: $model->getAttributes());
    }

    // -------------------------------------------------------------------------
    // Core Logic
    // -------------------------------------------------------------------------

    private function log(Model $model, string $event, ?array $attributes = null, ?array $modified = null, ?array $original = null): void
    {
        // ── SYNCHRONOUS CAPTURE ───────────────────────────────────────────────
        // Everything here runs inside the current request cycle.
        // We grab all volatile states NOW before the response is sent:
        //   • Execution context (origin, trace, user, url)
        //   • Model identifiers (morph class + primary key)
        //   • Payload split into attributes / modified / original columns
        //
        // We intentionally do NOT write to the DB or dispatch jobs here —
        // that would add latency to the user's TTFB.

        // Cap oversized string values so a single wide/long-text column (e.g.
        // an HTML description) can't bloat the stored JSON payload. Applies to
        // every payload column; a null length disables truncation.
        $maxLength = config('system-log.attributes.max_value_length');

        if ($maxLength !== null) {
            $attributes = self::truncateValues($attributes, (int) $maxLength);
            $modified = self::truncateValues($modified, (int) $maxLength);
            $original = self::truncateValues($original, (int) $maxLength);
        }

        $context = ExecutionContext::capture();
        $loggableType = $model->getMorphClass();
        $loggableId = (string) $model->getKey();

        // Snapshot the ambient scope (e.g. the active tenant) NOW, while the
        // originating request/command is still bound. The deferred write below
        // runs after that lifecycle ends, by which point scoped state — like a
        // multi-tenant database connection — may have reverted to its default.
        $bridge = $this->scopeBridge();
        $scope = $bridge?->capture();

        // ── DEFERRED EXECUTION ────────────────────────────────────────────────
        // defer() schedules the closure to run after the HTTP response has been
        // sent to the browser (or after the CLI command exits), keeping TTFB
        // at zero.  The closure captures all local variables by value, so it
        // remains self-contained even after the model instance is GC'd.

        \Illuminate\Support\defer(static function () use ($event, $attributes, $modified, $original, $context, $loggableType, $loggableId, $bridge, $scope): void {
            // Step 1 — Write the heavy payload to the database first.
            //
            // This bypasses the SQS 256 KB limit: the JSON payload (which can
            // be several hundred KB for large model diffs) never enters the
            // queue.  The queue job receives only a lightweight integer log ID.

            $write = static function () use ($event, $attributes, $modified, $original, $context, $loggableType, $loggableId): void {
                /** @var class-string<Model> $modelClass */
                $modelClass = config('system-log.database.model', SystemLog::class);

                /** @var SystemLog $log */
                $log = $modelClass::create([
                    'loggable_type' => $loggableType,
                    'loggable_id' => $loggableId,
                    'event' => $event,
                    'attributes' => $attributes,
                    'modified' => $modified,
                    'original' => $original,
                    'status' => LogStatus::Pending,
                ]);

                // Step 2 — Dispatch the lightweight job that enriches the log
                // record with execution context (origin, trace, user_id,
                // metadata).
                //
                // The job payload going to SQS is tiny:
                //   • log ID → is 8 bytes
                //   • context → ~2 KB at most

                ProcessLogContext::dispatch($log->id, $context)
                    ->onConnection(config('system-log.queue.connection'))
                    ->onQueue(config('system-log.queue.name', 'default'));
            };

            // Restore the captured scope around the write so the row lands on
            // the correct (e.g. tenant) connection. Without a bridge the write
            // runs inline, preserving the previous behaviour.
            $bridge ? $bridge->restore($scope, $write) : $write();
        })->name("system-log.defer-log-{$model->getKey()}-{$model->getMorphClass()}-{$event}")->always();
    }

    /**
     * Recursively truncate string values longer than $max characters, appending
     * a marker so truncation is visible in the stored payload. Non-string
     * scalars and nested array structure are preserved.
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private static function truncateValues(?array $data, int $max): ?array
    {
        if ($data === null || $max <= 0) {
            return $data;
        }

        array_walk_recursive($data, static function (mixed &$value) use ($max): void {
            if (is_string($value) && mb_strlen($value) > $max) {
                $value = mb_substr($value, 0, $max).'…[truncated]';
            }
        });

        return $data;
    }

    /**
     * Resolve the optional scope bridge that restores ambient context around
     * the deferred write. Returns null when none is configured.
     */
    private function scopeBridge(): ?ScopeBridge
    {
        /** @var class-string<ScopeBridge>|ScopeBridge|null $bridge */
        $bridge = config('system-log.scope');

        if ($bridge === null) {
            return null;
        }

        return $bridge instanceof ScopeBridge ? $bridge : app($bridge);
    }
}
