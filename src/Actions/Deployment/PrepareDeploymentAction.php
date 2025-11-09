<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Services\LockManager;
use Shaf\LaravelDeployer\Services\ReleaseManager;
use Shaf\LaravelDeployer\Support\Abstract\DeploymentAction;
use Shaf\LaravelDeployer\Deployer;

class PrepareDeploymentAction extends DeploymentAction
{
    public function __construct(
        protected Deployer $deployer,
        protected ?LockManager $lockManager = null,
        protected ?ReleaseManager $releaseManager = null
    ) {
        parent::__construct($deployer);
        $this->lockManager = $lockManager ?? new LockManager($deployer);
        $this->releaseManager = $releaseManager ?? new ReleaseManager($deployer);
    }

    public function execute(): string
    {
        $this->displayDeploymentInfo();
        $this->setupDeploymentStructure();
        $this->lockManager->checkLock();
        $this->lockManager->lock();

        $releaseName = $this->deployer->getReleaseName();
        $this->releaseManager->displayReleaseInfo();

        $releasePath = $this->releaseManager->createRelease($releaseName);

        return $releasePath;
    }

    /**
     * Display deployment information
     */
    protected function displayDeploymentInfo(): void
    {
        $user = $this->deployer->runLocally('git config --get user.name', false);
        $branch = $this->deployer->get('branch', 'HEAD');
        $releaseName = $this->deployer->getReleaseName();

        $this->writeln("info deploying {$branch} to {$this->deployer->get('hostname')} (release {$releaseName})");
    }

    /**
     * Setup the deployment directory structure
     */
    protected function setupDeploymentStructure(): void
    {
        $deployPath = $this->getDeployPath();

        // Set up deployment structure quietly
        $this->writeln("Setting up deployment structure...");
        
        $this->runBatch([
            "[ -d {$deployPath} ] || mkdir -p {$deployPath}",
            "cd {$deployPath} && [ -d .dep ] || mkdir .dep",
            "cd {$deployPath} && [ -d releases ] || mkdir releases",
            "cd {$deployPath} && [ -d shared ] || mkdir shared",
        ]);

        // Check if current exists and is not a symlink
        $result = $this->runQuietly("if [ ! -L {$deployPath}/current ] && [ -d {$deployPath}/current ]; then echo 'legacy_current_found'; fi");
        if (!empty(trim($result))) {
            $this->writeln("⚠ Legacy 'current' directory found (not a symlink)", 'comment');
        }
    }
}
