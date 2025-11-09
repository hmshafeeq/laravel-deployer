<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Services\OutputService;

class BuildAssetsAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected string $basePath
    ) {
    }

    public function execute(): void
    {
        $this->output->info("Building assets...");

        try {
            // Check if package.json exists
            $packageJsonExists = file_exists("{$this->basePath}/package.json");

            if (!$packageJsonExists) {
                $this->output->warn("No package.json found, skipping asset build");
                return;
            }

            // Install npm dependencies and build
            $this->executor->execute("cd {$this->basePath} && npm install", true);
            $this->executor->execute("cd {$this->basePath} && npm run build", true);

            $this->output->success("Assets built successfully");
        } catch (\Exception $e) {
            $this->output->error("Asset build failed: " . $e->getMessage());
            throw $e;
        }
    }
}
