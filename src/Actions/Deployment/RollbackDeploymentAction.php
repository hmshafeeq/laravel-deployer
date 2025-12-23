<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Services\ReleaseManager;
use Shaf\LaravelDeployer\Support\Abstract\DeploymentAction;

class RollbackDeploymentAction extends DeploymentAction
{
    public function __construct(
        protected Deployer $deployer,
        protected ?ReleaseManager $releaseManager = null
    ) {
        parent::__construct($deployer);
        $this->releaseManager = $releaseManager ?? new ReleaseManager($deployer);
    }

    public function execute(?string $targetRelease = null): string
    {
        $rollbackInfo = $this->releaseManager->getRollbackInfo();

        if (! $rollbackInfo['can_rollback']) {
            throw new \RuntimeException('No previous release available for rollback');
        }

        // Use specified target or previous release
        $target = $targetRelease ?? $rollbackInfo['previous'];

        if (! $target) {
            throw new \RuntimeException('No target release specified for rollback');
        }

        $this->performRollback($target);

        return $target;
    }

    /**
     * Perform the rollback to a specific release
     */
    protected function performRollback(string $targetRelease): void
    {
        $deployPath = $this->getDeployPath();
        $releasesPath = "{$deployPath}/releases";
        $targetPath = "{$releasesPath}/{$targetRelease}";
        $currentPath = "{$deployPath}/current";

        $this->writeln("🔄 Rolling back to release: {$targetRelease}", 'info');

        // Verify target release exists
        $exists = $this->deployer->test("[ -d {$targetPath} ]");
        if (! $exists) {
            throw new \RuntimeException("Release {$targetRelease} does not exist");
        }

        // Create release symlink
        $this->writeln("run ln -nfs {$targetPath} {$deployPath}/release");
        $this->cmd("ln -nfs {$targetPath} {$deployPath}/release");

        // Atomic swap to new release
        $this->writeln("run mv -fT {$deployPath}/release {$currentPath}");
        $this->cmd("mv -fT {$deployPath}/release {$currentPath}");

        $this->writeln("✓ Symlink updated to: {$targetRelease}", 'info');
    }
}
