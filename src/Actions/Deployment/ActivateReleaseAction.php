<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Services\LockManager;
use Shaf\LaravelDeployer\Services\ReleaseManager;
use Shaf\LaravelDeployer\Services\SharedResourceLinker;
use Shaf\LaravelDeployer\Support\Abstract\DeploymentAction;

class ActivateReleaseAction extends DeploymentAction
{
    public function __construct(
        protected Deployer $deployer,
        protected ?ReleaseManager $releaseManager = null,
        protected ?LockManager $lockManager = null,
        protected ?SharedResourceLinker $resourceLinker = null
    ) {
        parent::__construct($deployer);
        $this->releaseManager = $releaseManager ?? new ReleaseManager($deployer);
        $this->lockManager = $lockManager ?? new LockManager($deployer);
        $this->resourceLinker = $resourceLinker ?? new SharedResourceLinker($deployer);
    }

    public function execute(): void
    {
        $deployPath = $this->getDeployPath();

        // Create atomic symlink from release to current
        $this->activateRelease($deployPath);

        // Link deployment metadata
        $this->resourceLinker->linkDeploymentMetadata();

        // Cleanup old releases
        $keepReleases = config('laravel-deployer.paths.keep_releases', 3);
        $this->releaseManager->cleanupOldReleases($keepReleases);

        // Unlock deployment
        $this->lockManager->unlock();

        // Display success message
        $this->writeln('info successfully deployed!');
    }

    /**
     * Atomically activate the new release
     */
    protected function activateRelease(string $deployPath): void
    {
        $this->writeln('run (man mv 2>&1 || mv -h 2>&1 || mv --help 2>&1) | grep -- --no-target-directory || true');
        $supportsNoTarget = $this->cmd('(man mv 2>&1 || mv -h 2>&1 || mv --help 2>&1) | grep -- --no-target-directory || true');
        if (! empty($supportsNoTarget)) {
            $this->writeln('       -T, --no-target-directory');
        }

        $this->writeln("run mv -T {$deployPath}/release {$deployPath}/current");
        $this->cmd("mv -T {$deployPath}/release {$deployPath}/current");
    }
}
