<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use ViicSlen\SystemLog\Models\SystemLog;
use ViicSlen\SystemLog\Support\Compactor;
use ViicSlen\SystemLog\Support\LogOptions;

/**
 * Compacts every model with `compaction.enabled`, globally or per model, one chunk of rows at a time.
 *
 * A loggable's eligible `updated` rows are gone once it is compacted, so an interrupted run simply
 * resumes on the next one.
 */
class CompactCommand extends Command
{
    protected $signature = 'system-log:compact
        {--model=* : Only compact these model classes}
        {--chunk=500 : Loggables per query}';

    protected $description = 'Merge old system log update rows into one row per model per month';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        foreach ($this->option('model') ?: $this->candidateModels() as $class) {
            $options = LogOptions::for($class);

            if (! $options->compaction || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            [$compacted, $removed] = $this->compactModel($class, $options, $chunk);

            $this->line(sprintf('%s: %d loggable(s) compacted, %d row(s) merged away.', $class, $compacted, $removed));
        }

        return self::SUCCESS;
    }

    /**
     * Configured models plus every stored type, so a global `compaction.enabled` covers models
     * that have no entry of their own.
     *
     * @return list<string>
     */
    protected function candidateModels(): array
    {
        /** @var class-string<SystemLog> $model */
        $model = config('system-log.database.model', SystemLog::class);
        $stored = $model::query()->distinct()->pluck('loggable_type')->map(LogOptions::modelClass(...))->all();

        return array_values(array_unique([...LogOptions::configuredModels(), ...$stored]));
    }

    /**
     * Walks the old `updated` rows once, oldest first, on the (`event`, `created_at`) index. A
     * model skipped for changing too recently keeps its rows; the keyset moves past them.
     *
     * @return array{int, int}
     */
    protected function compactModel(string $class, LogOptions $options, int $chunk): array
    {
        $type = LogOptions::morphClass($class);
        // Whole months only: a month is compacted once, after its last day passes the window.
        $before = now()->subDays($options->compactAfterDays)->startOfMonth();
        /** @var class-string<SystemLog> $model */
        $model = config('system-log.database.model', SystemLog::class);
        // A compacted model's old rows are gone, so only skipped ones can come up again.
        $skipped = [];
        $last = null;
        $compacted = 0;
        $removed = 0;

        do {
            $rows = $model::query()
                ->toBase()
                ->where('event', 'updated')
                ->where('created_at', '<', $before)
                ->when($last !== null, fn ($query) => $query->where(fn ($query) => $query
                    ->where('created_at', '>', $last->created_at)
                    ->orWhere(fn ($query) => $query->where('created_at', $last->created_at)->where('id', '>', $last->id))))
                ->where('loggable_type', $type)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($chunk)
                ->get(['id', 'created_at', 'loggable_id']);

            foreach ($rows->pluck('loggable_id')->unique() as $id) {
                if (isset($skipped[$id])) {
                    continue;
                }

                $result = Compactor::compact($type, $id, $before);

                if ($result === null) {
                    $skipped[$id] = true;

                    continue;
                }

                $compacted++;
                $removed += $result;
            }

            $last = $rows->last();
        } while ($rows->count() === $chunk);

        return [$compacted, $removed];
    }
}
