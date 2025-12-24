<?php

namespace Shaf\LaravelDeployer;

use Illuminate\Support\ServiceProvider;
use Shaf\LaravelDeployer\Commands\ClearCommand;
use Shaf\LaravelDeployer\Commands\DatabaseCommand;
use Shaf\LaravelDeployer\Commands\DeployCommand;
use Shaf\LaravelDeployer\Commands\InstallCommand;
use Shaf\LaravelDeployer\Commands\MigrateCommand;
use Shaf\LaravelDeployer\Commands\ProvisionCommand;
use Shaf\LaravelDeployer\Commands\RollbackCommand;
use Shaf\LaravelDeployer\Commands\SshKeyGenerateCommand;

class LaravelDeployerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Configuration is now handled via .deploy/deploy.json
        // No Laravel config file needed
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Register commands
            $this->commands([
                InstallCommand::class,
                DeployCommand::class,
                ProvisionCommand::class,
                RollbackCommand::class,
                ClearCommand::class,
                MigrateCommand::class,
                DatabaseCommand::class,
                SshKeyGenerateCommand::class,
            ]);
        }
    }
}
