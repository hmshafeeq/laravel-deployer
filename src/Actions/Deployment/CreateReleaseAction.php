<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;

class CreateReleaseAction
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

        $this->output->info("Creating release directory: {$this->releaseName}");

        // Create release directory
        $this->executor->execute("mkdir -p {$releasePath}");

        $this->output->success("Release directory created");
    }
}
