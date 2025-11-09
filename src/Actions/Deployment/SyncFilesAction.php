<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;
use Shaf\LaravelDeployer\Services\RsyncService;

class SyncFilesAction
{
    public function __construct(
        protected RsyncService $rsyncService,
        protected OutputService $output,
        protected DeploymentConfig $config,
        protected string $releaseName
    ) {
    }

    public function execute(): void
    {
        $this->output->info("Syncing files to server...");

        $destination = "{$this->config->remoteUser}@{$this->config->hostname}:{$this->config->deployPath}/releases/{$this->releaseName}";

        try {
            $this->rsyncService->sync($destination);
            $this->output->success("Files synced successfully");
        } catch (\Exception $e) {
            $this->output->error("File sync failed: " . $e->getMessage());
            throw $e;
        }
    }
}
