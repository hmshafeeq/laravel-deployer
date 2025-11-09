<?php

namespace Shaf\LaravelDeployer\Actions\System;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Services\OutputService;

class RestartNginxAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output
    ) {
    }

    public function execute(): void
    {
        $this->output->info("Restarting Nginx...");

        try {
            // Test nginx configuration first
            $this->executor->execute("sudo nginx -t");

            // Reload nginx (graceful restart)
            $this->executor->execute("sudo systemctl reload nginx");

            $this->output->success("Nginx reloaded successfully");
        } catch (\Exception $e) {
            $this->output->error("Failed to reload Nginx: " . $e->getMessage());
            throw $e;
        }
    }
}
