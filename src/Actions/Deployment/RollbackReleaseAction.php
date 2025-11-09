<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;

class RollbackReleaseAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config,
        protected string $targetRelease
    ) {
    }

    public function execute(): void
    {
        $deployPath = $this->config->deployPath;
        $targetPath = "{$deployPath}/releases/{$this->targetRelease}";
        $currentPath = "{$deployPath}/current";

        $this->output->info("Rolling back to release: {$this->targetRelease}");

        // Verify target release exists
        $exists = trim($this->executor->execute("test -d {$targetPath} && echo 'OK' || echo 'FAIL'"));

        if ($exists !== 'OK') {
            throw new \RuntimeException("Target release does not exist: {$this->targetRelease}");
        }

        // Create atomic symlink
        $this->executor->execute("ln -nfs {$targetPath} {$currentPath}");

        $this->output->success("Rolled back to: {$this->targetRelease}");
    }

    public function getAvailableReleases(): array
    {
        $deployPath = $this->config->deployPath;

        $output = $this->executor->execute("ls -t {$deployPath}/releases 2>/dev/null || echo ''");

        if (empty(trim($output))) {
            return [];
        }

        return array_filter(array_map('trim', explode("\n", trim($output))));
    }

    public function getCurrentRelease(): ?string
    {
        $deployPath = $this->config->deployPath;
        $currentPath = "{$deployPath}/current";

        try {
            $target = $this->executor->execute("readlink {$currentPath}");
            return basename(trim($target));
        } catch (\Exception $e) {
            return null;
        }
    }
}
