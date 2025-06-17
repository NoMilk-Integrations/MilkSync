<?php

namespace RootAccessPlease\MilkSync;

use Illuminate\Support\ServiceProvider;
use RootAccessPlease\MilkSync\Commands\SyncDatabaseCommand;

class MilkSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/Config/milksync.php',
            'milksync'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncDatabaseCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/Config/milksync.php' => config_path('milksync.php'),
            ], 'milk-sync-config');
        }
    }

    public function provides(): array
    {
        return [
            SyncDatabaseCommand::class,
        ];
    }
}
