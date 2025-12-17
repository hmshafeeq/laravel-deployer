<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Support\Abstract\DeploymentAction;

/**
 * Build frontend assets locally before deployment
 *
 * This action compiles frontend assets (CSS, JS) locally using the build
 * command (npm/yarn/pnpm), which is then synced to the server.
 */
class BuildAssetsLocallyAction extends DeploymentAction
{
    protected string $buildCommand;

    /**
     * Create a new BuildAssetsLocallyAction instance
     *
     * @param Deployer $deployer
     * @param string $buildCommand Build command to run (default: npm run build)
     */
    public function __construct(
        protected Deployer $deployer,
        string $buildCommand = 'npm run build'
    ) {
        parent::__construct($deployer);
        $this->buildCommand = $buildCommand;
    }

    /**
     * Execute the asset build operation
     *
     * @return void
     */
    public function execute(): void
    {
        $this->writeln("🏗️  Building frontend assets locally...", 'info');

        try {
            $this->deployer->runLocalCommand($this->buildCommand);
            $this->writeln("✅ Frontend assets built successfully", 'info');
        } catch (\Exception $e) {
            $this->writeln("❌ Failed to build frontend assets", 'error');
            throw $e;
        }
    }
}
