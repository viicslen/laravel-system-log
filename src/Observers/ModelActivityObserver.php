<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Observers;

use Illuminate\Database\Eloquent\Model;
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

        $context = ExecutionContext::capture();
        $loggableType = $model->getMorphClass();
        $loggableId = (string) $model->getKey();

        // ── DEFERRED EXECUTION ────────────────────────────────────────────────
        // defer() schedules the closure to run after the HTTP response has been
        // sent to the browser (or after the CLI command exits), keeping TTFB
        // at zero.  The closure captures all local variables by value, so it
        // remains self-contained even after the model instance is GC'd.

        \Illuminate\Support\defer(static function () use ($event, $attributes, $modified, $original, $context, $loggableType, $loggableId): void {
            // Step 1 — Write the heavy payload to the database first.
            //
            // This bypasses the SQS 256 KB limit: the JSON payload (which can
            // be several hundred KB for large model diffs) never enters the
            // queue.  The queue job receives only a lightweight integer log ID.

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

            // Step 2 — Dispatch the lightweight job that enriches the log record
            // with execution context (origin, trace, user_id, metadata).
            //
            // The job payload going to SQS is tiny:
            //   • log ID → is 8 bytes
            //   • context → ~2 KB at most

            ProcessLogContext::dispatch($log->id, $context)
                ->onConnection(config('system-log.queue.connection'))
                ->onQueue(config('system-log.queue.name', 'default'));
        })->name("system-log.defer-log-{$model->getKey()}-{$model->getMorphClass()}-{$event}")->always();
    }
}
