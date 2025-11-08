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
        // Register the Deployer singleton
        $this->app->singleton('deployer', function ($app) {
            // Default configuration - will be overridden when commands create instances
            return new Deployer('local', [
                'hostname' => 'localhost',
                'remote_user' => 'deploy',
                'deploy_path' => '/var/www/app',
                'local' => true,
            ]);
        });

        // Register the facade alias
        $loader = \Illuminate\Foundation\AliasLoader::getInstance();
        $loader->alias('Deployer', \Shaf\LaravelDeployer\Facades\Deployer::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
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
