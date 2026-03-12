<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use ViicSlen\SystemLog\DataTransferObjects\ExecutionContext;
use ViicSlen\SystemLog\Enums\LogStatus;
use ViicSlen\SystemLog\Models\SystemLog;

/**
 * Enriches a pending SystemLog record with execution context data.
 *
 * This job deliberately carries only two things:
 *   1. An integer log ID — the heavy payload is already in the DB.
 *   2. A small ExecutionContext DTO (~2 KB) — origin, trace, user, url, input.
 */
class ProcessLogContext implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of queue attempts before the job is considered failed.
     * Kept low because context enrichment is best-effort — a stale 'pending'
     * log is far less harmful than an infinite retry loop.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying after a failure.
     */
    public int $backoff = 10;

    public function __construct(
        private readonly int $logId,
        private readonly ExecutionContext $context,
    ) {}

    public function handle(): void
    {
        /** @var class-string<SystemLog> $modelClass */
        $modelClass = config('system-log.model', SystemLog::class);

        /** @var SystemLog|null $log */
        $log = $modelClass::find($this->logId);

        // Guard: log may have been pruned or deleted between defer and execution.
        if ($log === null) {
            return;
        }

        $log->update([
            'origin' => $this->context->origin,
            'trace' => $this->context->trace,
            'user_id' => $this->context->userId,
            'metadata' => [
                'url' => $this->context->url,
                'input' => $this->context->input,
            ],
            'status' => LogStatus::Complete,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        // Mark the record as failed so monitoring dashboards / pruning jobs
        // can identify log entries that were never fully enriched.
        /** @var class-string<SystemLog> $modelClass */
        $modelClass = config('system-log.model', SystemLog::class);

        $modelClass::where('id', $this->logId)
            ->where('status', LogStatus::Pending)
            ->update(['status' => LogStatus::Failed]);
    }
}
