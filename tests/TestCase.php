<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use ViicSlen\SystemLog\SystemLogServiceProvider;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            SystemLogServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('system-log.enabled', true);
        $app['config']->set('system-log.database.connection', null);
        $app['config']->set('system-log.database.table', 'system_logs');
        $app['config']->set('system-log.queue.connection', 'sync');
        $app['config']->set('system-log.queue.name', 'default');
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_system_logs_table.php.stub';

        $migration->up();

        // Auxiliary table used by test models
        $this->app['db']->connection()->getSchemaBuilder()->create('test_models', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }
}
