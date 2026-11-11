<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Console;

use Illuminate\Console\Command;
use ViicSlen\SystemLog\Models\SystemLog;
use ViicSlen\SystemLog\Support\Compactor;
use ViicSlen\SystemLog\Support\LogOptions;

/**
 * Compacts every model configured with `compaction.enabled`, one chunk of loggables at a time.
 *
 * A loggable's eligible `updated` rows are gone once it is compacted, so each chunk query picks up
 * where the last left off and an interrupted run simply resumes on the next one.
 */
class CompactCommand extends Command
{
    protected $signature = 'system-log:compact
        {--model=* : Only compact these model classes}
        {--chunk=500 : Loggables per query}';

    protected $description = 'Merge old system log update rows into one row per model per month';

    public function handle(): int
    {
        $models = $this->option('model') ?: LogOptions::configuredModels();
        $chunk = max(1, (int) $this->option('chunk'));

        foreach ($models as $class) {
            $options = LogOptions::for($class);

            if (! $options->compaction) {
                continue;
            }

            [$compacted, $removed] = $this->compactModel($class, $options, $chunk);

            $this->line(sprintf('%s: %d loggable(s) compacted, %d row(s) merged away.', $class, $compacted, $removed));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{int, int}
     */
    protected function compactModel(string $class, LogOptions $options, int $chunk): array
    {
        $type = LogOptions::morphClass($class);
        // Whole months only: a month is compacted once, after its last day passes the window.
        $before = now()->subDays($options->compactAfterDays)->startOfMonth();
        $skipped = [];
        $compacted = 0;
        $removed = 0;

        do {
            /** @var class-string<SystemLog> $model */
            $model = config('system-log.database.model', SystemLog::class);

            $ids = $model::query()
                ->where('loggable_type', $type)
                ->where('event', 'updated')
                ->where('created_at', '<', $before)
                ->when($skipped !== [], fn ($query) => $query->whereNotIn('loggable_id', $skipped))
                ->distinct()
                ->limit($chunk)
                ->pluck('loggable_id');

            foreach ($ids as $id) {
                $result = Compactor::compact($type, $id, $before);

                if ($result === null) {
                    $skipped[] = $id;

                    continue;
                }

                $compacted++;
                $removed += $result;
            }
        } while ($ids->isNotEmpty());

        return [$compacted, $removed];
    }
}
