<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;

class SetupDeploymentStructureAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config
    ) {
    }

    public function execute(): void
    {
        $deployPath = $this->config->deployPath;

        $this->output->info("Setting up deployment structure...");

        // Create main directories
        $directories = [
            $deployPath,
            "{$deployPath}/.dep",
            "{$deployPath}/releases",
            "{$deployPath}/shared",
            "{$deployPath}/shared/storage",
            "{$deployPath}/shared/storage/app",
            "{$deployPath}/shared/storage/framework",
            "{$deployPath}/shared/storage/framework/cache",
            "{$deployPath}/shared/storage/framework/sessions",
            "{$deployPath}/shared/storage/framework/views",
            "{$deployPath}/shared/storage/logs",
        ];

        foreach ($directories as $dir) {
            $this->executor->execute("mkdir -p {$dir}");
        }

        $this->output->success("Deployment structure created");
    }
}
