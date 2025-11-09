<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;

class SymlinkReleaseAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config,
        protected string $releaseName
    ) {
    }

    public function execute(): void
    {
        $deployPath = $this->config->deployPath;
        $releasePath = "{$deployPath}/releases/{$this->releaseName}";
        $currentPath = "{$deployPath}/current";

        $this->output->info("Symlinking new release...");

        // Create atomic symlink by using ln -nfs
        // -n: no dereference if current is a symlink to a directory
        // -f: force (remove existing symlink)
        // -s: symbolic link
        $this->executor->execute("ln -nfs {$releasePath} {$currentPath}");

        $this->output->success("Release symlinked to current");
    }
}
