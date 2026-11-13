<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Observers;

use Illuminate\Database\Eloquent\Model;
use ViicSlen\SystemLog\Contracts\ScopeBridge;
use ViicSlen\SystemLog\DTO\ExecutionContext;
use ViicSlen\SystemLog\Enums\LogStatus;
use ViicSlen\SystemLog\Jobs\ProcessLogContext;
use ViicSlen\SystemLog\Models\SystemLog;
use ViicSlen\SystemLog\Support\LogOptions;
use ViicSlen\SystemLog\Support\PayloadEncoder;

class ModelActivityObserver
{
    private static int $sequence = 0;

    // -------------------------------------------------------------------------
    // Event Handlers
    // -------------------------------------------------------------------------

    public function created(Model $model): void
    {
        $options = LogOptions::for($model);

        $this->log($model, 'created', attributes: $this->snapshot($model, $options, $options->snapshotCreated));
    }

    public function updated(Model $model): void
    {
        $options = LogOptions::for($model);

        // getChanges() returns only the columns that actually changed. Skip the
        // row when nothing but excluded columns (and the timestamp riding along
        // with them) changed, e.g. touch() calls.
        $changes = PayloadEncoder::withoutExcluded($options, $model->getChanges());

        if (array_diff_key($changes, array_flip(array_filter([$model->getUpdatedAtColumn()]))) === []) {
            return;
        }

        if ($options->mode === LogOptions::DIFF) {
            [$modified, $original] = PayloadEncoder::change($options, $model->getRawOriginal(), $changes, $model->getCasts());

            $this->log($model, 'updated', modified: $modified, original: $original,
                periodic: $options->snapshotEvery > 0 ? $this->snapshot($model, $options, true) : null,
                every: $options->snapshotEvery,
            );

            return;
        }

        [$modified, $original] = PayloadEncoder::change(
            $options,
            array_intersect_key($model->getOriginal(), $changes),
            $changes,
            $model->getCasts(),
        );

        $this->log($model, 'updated',
            attributes: $this->snapshot($model, $options, true),
            modified: $modified,
            original: $original,
        );
    }

    public function deleted(Model $model): void
    {
        $options = LogOptions::for($model);

        $this->log($model, 'deleted', attributes: $this->snapshot($model, $options, $options->snapshotDeleted));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshot(Model $model, LogOptions $options, bool $keep): ?array
    {
        if ($options->mode === LogOptions::DIFF && ! $keep) {
            return null;
        }

        return PayloadEncoder::snapshot($options, $model->getAttributes(), $model->getCasts());
    }

    // -------------------------------------------------------------------------
    // Core Logic
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $periodic  a snapshot stored only when none of the model's last `$every - 1` rows holds one
     */
    private function log(Model $model, string $event, ?array $attributes = null, ?array $modified = null, ?array $original = null, ?array $periodic = null, int $every = 0): void
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
        // The change's own time, not the deferred insert's: two requests can finish their deferred
        // writes in the reverse order of the changes they log.
        $changedAt = now();
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

        $deferred = static function () use ($event, $attributes, $modified, $original, $periodic, $every, $context, $changedAt, $loggableType, $loggableId, $bridge, $scope): void {
            // Step 1 — Write the heavy payload to the database first.
            //
            // This bypasses the SQS 256 KB limit: the JSON payload (which can
            // be several hundred KB for large model diffs) never enters the
            // queue.  The queue job receives only a lightweight integer log ID.

            $write = static function () use ($event, $attributes, $modified, $original, $periodic, $every, $context, $changedAt, $loggableType, $loggableId): void {
                /** @var class-string<SystemLog> $modelClass */
                $modelClass = config('system-log.database.model', SystemLog::class);

                // Decided at write time: deferred writes land in order, so the rows before this one exist.
                if ($periodic !== null && ($every === 1 || ! $modelClass::query()
                    ->where('loggable_type', $loggableType)
                    ->where('loggable_id', $loggableId)
                    ->orderByDesc('id')
                    ->limit($every - 1)
                    ->toBase()
                    ->selectRaw('attributes is not null as anchored')
                    ->pluck('anchored')
                    ->contains(static fn (mixed $anchored): bool => (bool) $anchored))) {
                    $attributes = $periodic;
                }

                /** @var SystemLog $log */
                $log = $modelClass::forceCreate([
                    'loggable_type' => $loggableType,
                    'loggable_id' => $loggableId,
                    'event' => $event,
                    'attributes' => $attributes,
                    'modified' => $modified,
                    'original' => $original,
                    'status' => LogStatus::Pending,
                    'created_at' => $changedAt,
                    'changed_at_micros' => (int) $changedAt->format('Uu'),
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
        };

        // Unique per write: defer() replaces a callback with the same name, which dropped every
        // save of a model but the last one in a request or job.
        $name = 'system-log.defer-log-'.++self::$sequence."-{$loggableId}-{$loggableType}-{$event}";
        $register = static fn () => \Illuminate\Support\defer($deferred)->name($name)->always();

        // A change its transaction rolls back never happened, so it is logged only once committed.
        $connection = $model->getConnection();
        $connection->transactionLevel() > 0 ? $connection->afterCommit($register) : $register();
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
