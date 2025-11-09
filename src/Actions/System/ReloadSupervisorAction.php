<?php

namespace Shaf\LaravelDeployer\Actions\System;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Services\OutputService;

class ReloadSupervisorAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output
    ) {
    }

    public function execute(): void
    {
        $this->output->info("Reloading Supervisor...");

        try {
            // Reread and update supervisor configuration
            $this->executor->execute("sudo supervisorctl reread");
            $this->executor->execute("sudo supervisorctl update");

            // Restart all programs
            $this->executor->execute("sudo supervisorctl restart all");

            $this->output->success("Supervisor reloaded successfully");
        } catch (\Exception $e) {
            $this->output->warn("Supervisor reload failed: " . $e->getMessage());
            // Don't throw - supervisor might not be installed/configured
        }
    }
}
