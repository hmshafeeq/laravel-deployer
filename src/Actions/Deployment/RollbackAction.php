<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class RollbackAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $deployPath = $this->deployer->getDeployPath();
        $releasesPath = "{$deployPath}/releases";
        $currentPath = "{$deployPath}/current";

        // Get rollback information
        $info = $this->releaseService->getRollbackInfo();

        if (!$info['can_rollback']) {
            $this->deployer->writeln("Cannot rollback: no previous release available", 'error');
            throw new \RuntimeException("Cannot rollback: no previous release available");
        }

        $targetRelease = $info['previous'];
        $targetPath = "{$releasesPath}/{$targetRelease}";

        $this->deployer->writeln("🔄 Rolling back to release: {$targetRelease}", 'info');

        // Verify target release exists
        $exists = $this->deployer->test("[ -d {$targetPath} ]");
        if (!$exists) {
            throw new \RuntimeException("Release {$targetRelease} does not exist");
        }

        // Create release symlink
        $this->deployer->writeln("run ln -nfs {$targetPath} {$deployPath}/release");
        $this->deployer->run("ln -nfs {$targetPath} {$deployPath}/release");

        // Atomic swap to new release
        $this->deployer->writeln("run mv -fT {$deployPath}/release {$currentPath}");
        $this->deployer->run("mv -fT {$deployPath}/release {$currentPath}");

        $this->deployer->writeln("✓ Symlink updated to: {$targetRelease}", 'info');
    }

    public function getName(): string
    {
        return 'rollback';
    }
}
