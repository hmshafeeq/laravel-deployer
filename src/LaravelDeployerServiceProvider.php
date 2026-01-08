<?php

namespace Shaf\LaravelDeployer;

use Illuminate\Support\ServiceProvider;
use Shaf\LaravelDeployer\Commands\DatabaseCommand;
use Shaf\LaravelDeployer\Commands\DeployCommand;
use Shaf\LaravelDeployer\Commands\DiagnoseCommand;
use Shaf\LaravelDeployer\Commands\ReleaseCommand;
use Shaf\LaravelDeployer\Commands\ServerCommand;
use Shaf\LaravelDeployer\Commands\SetupCommand;

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
                DeployCommand::class,      // deployer {env}
                ReleaseCommand::class,     // deployer:release {action} {env}
                ServerCommand::class,      // deployer:server {action} {env}
                SetupCommand::class,       // deployer:setup {action} {env?}
                DatabaseCommand::class,    // deployer:db {action} {target?}
                DiagnoseCommand::class,    // deployer:diagnose {env}
            ]);
        }
    }
}
