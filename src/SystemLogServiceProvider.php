<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog;

use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use ViicSlen\SystemLog\Models\SystemLog;

class SystemLogServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('system-log')
            ->hasConfigFile()
            ->hasMigration('create_system_logs_table')
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations();
            });
    }

    public function packageBooted(): void
    {
        // Bind the configurable model so downstream code can resolve it via
        // the container rather than reading config directly.
        /** @var class-string<SystemLog> $modelClass */
        $modelClass = config('system-log.model', SystemLog::class);

        $this->app->bind(SystemLog::class, $modelClass);

        // Compile glob-style skip_paths and include_paths into PCRE patterns
        // once at boot, so the hot path inside ExecutionContext::captureTrace()
        // pays zero compilation cost per model event. Both compiled arrays are
        // stored in config, so they are accessible without a dedicated singleton.
        $compile = static function (array $patterns): array {
            return array_map(static function (string $pattern): string {
                // Escape all regex meta-characters first, then convert the
                // glob wildcards back: \* → .* and \? → .
                $escaped = preg_quote($pattern, '#');
                $escaped = str_replace(['\*', '\?'], ['.*', '.'], $escaped);

                return '#'.$escaped.'#';
            }, $patterns);
        };

        /** @var array<string> $rawSkip */
        $rawSkip = config('system-log.backtrace.skip_paths', []);

        /** @var array<string> $rawInclude */
        $rawInclude = config('system-log.backtrace.include_paths', []);

        config([
            'system-log.backtrace.compiled_patterns'         => $compile($rawSkip),
            'system-log.backtrace.compiled_include_patterns' => $compile($rawInclude),
        ]);
    }
}
