<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Actions\Service\RestartPhpFpmAction;
use Shaf\LaravelDeployer\Actions\Service\RestartNginxAction;
use Shaf\LaravelDeployer\Actions\Service\ReloadSupervisorAction;

class ServiceTasks
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
    }

    /**
     * Restart PHP-FPM service(s)
     */
    public function restartPhpFpm(): void
    {
        $this->deployer->task('php-fpm:restart', function () {
            RestartPhpFpmAction::run($this->deployer);
        });
    }

    /**
     * Restart Nginx web server
     */
    public function restartNginx(): void
    {
        $this->deployer->task('nginx:restart', function () {
            RestartNginxAction::run($this->deployer);
        });
    }

    /**
     * Reload Supervisor process manager
     */
    public function reloadSupervisor(): void
    {
        $this->deployer->task('supervisor:reload', function () {
            ReloadSupervisorAction::run($this->deployer);
        });
    }
}
