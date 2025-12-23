<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Actions\Deployment\ActivateReleaseAction;
use Shaf\LaravelDeployer\Actions\Deployment\BuildAssetsLocallyAction;
use Shaf\LaravelDeployer\Actions\Deployment\ConfigureReleaseAction;
use Shaf\LaravelDeployer\Actions\Deployment\OptimizeApplicationAction;
use Shaf\LaravelDeployer\Actions\Deployment\PrepareDeploymentAction;
use Shaf\LaravelDeployer\Actions\Deployment\RunPostDeploymentScriptsAction;
use Shaf\LaravelDeployer\Actions\Deployment\SyncCodeAction;
use Shaf\LaravelDeployer\Deployer;

/**
 * Orchestrates the complete deployment workflow
 *
 * This service encapsulates the entire deployment process, making it
 * reusable across commands and testable as a unit.
 */
class DeploymentOrchestrator
{
    public function __construct(
        protected Deployer $deployer,
        protected ?HealthCheckService $healthCheckService = null,
        protected ?ServiceRestarter $serviceRestarter = null
    ) {
        $this->healthCheckService ??= new HealthCheckService($deployer);
        $this->serviceRestarter ??= new ServiceRestarter($deployer);
    }

    /**
     * Execute the complete deployment workflow
     */
    public function deploy(): void
    {
        $this->runPreDeploymentHealthChecks();
        $this->runDeploymentPhases();
        $this->runPostDeploymentPhases();
    }

    /**
     * Run pre-deployment health checks
     */
    public function runPreDeploymentHealthChecks(): void
    {
        $this->healthCheckService->runPreDeployment();
    }

    /**
     * Run main deployment phases
     */
    public function runDeploymentPhases(): void
    {
        // Prepare deployment (setup, lock, create release)
        PrepareDeploymentAction::run($this->deployer);

        // Build assets locally
        BuildAssetsLocallyAction::run($this->deployer);

        // Sync code to server
        SyncCodeAction::run($this->deployer);

        // Configure release (shared resources, vendors, permissions)
        ConfigureReleaseAction::run($this->deployer);

        // Optimize application (artisan commands, migrations)
        OptimizeApplicationAction::run($this->deployer);

        // Restart services
        $this->restartServices();

        // Activate release (symlink, cleanup, unlock)
        ActivateReleaseAction::run($this->deployer);
    }

    /**
     * Run post-deployment tasks
     */
    public function runPostDeploymentPhases(): void
    {
        // Post-deployment scripts
        RunPostDeploymentScriptsAction::run($this->deployer);

        // Health checks
        $this->runApplicationHealthChecks();

        // Link deployment metadata
        $resourceLinker = new SharedResourceLinker($this->deployer);
        $resourceLinker->linkDeploymentMetadata();
    }

    /**
     * Restart server services
     */
    protected function restartServices(): void
    {
        $this->serviceRestarter->restartAll(failSilently: true);
    }

    /**
     * Run application health checks
     */
    protected function runApplicationHealthChecks(): void
    {
        $this->healthCheckService->runPostDeployment();
    }
}
