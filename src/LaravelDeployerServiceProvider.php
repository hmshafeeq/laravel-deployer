<?php

namespace Shaf\LaravelDeployer;

use Illuminate\Support\ServiceProvider;
use Shaf\LaravelDeployer\Commands\InstallCommand;

class LaravelDeployerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }
}