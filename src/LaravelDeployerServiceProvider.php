<?php

namespace Shaf\LaravelDeployer;

use Illuminate\Support\ServiceProvider;
use Shaf\LaravelDeployer\Commands\ClearCommand;
use Shaf\LaravelDeployer\Commands\DatabaseBackupCommand;
use Shaf\LaravelDeployer\Commands\DatabaseDownloadCommand;
use Shaf\LaravelDeployer\Commands\DatabaseRestoreCommand;
use Shaf\LaravelDeployer\Commands\DatabaseUploadCommand;
use Shaf\LaravelDeployer\Commands\DeployCommand;
use Shaf\LaravelDeployer\Commands\InstallCommand;
use Shaf\LaravelDeployer\Commands\RollbackCommand;

class LaravelDeployerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laravel-deployer.php',
            'laravel-deployer'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish configuration file
            $this->publishes([
                __DIR__.'/../config/laravel-deployer.php' => config_path('laravel-deployer.php'),
            ], 'laravel-deployer-config');

            // Register commands
            $this->commands([
                InstallCommand::class,
                DeployCommand::class,
                RollbackCommand::class,
                ClearCommand::class,
                DatabaseRestoreCommand::class,
                DatabaseBackupCommand::class,
                DatabaseDownloadCommand::class,
                DatabaseUploadCommand::class,
            ]);
        }
    }
}
