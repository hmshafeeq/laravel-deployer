<?php

namespace Shaf\LaravelDeployer\Actions\System;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Services\OutputService;

class RestartPhpFpmAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output
    ) {
    }

    public function execute(): void
    {
        $this->output->info("Restarting PHP-FPM...");

        // Find running PHP-FPM services
        $phpFpmServices = $this->executor->execute(
            'systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""'
        );

        if (empty(trim($phpFpmServices))) {
            $this->output->warn("No running PHP-FPM service found");
            return;
        }

        $services = array_filter(explode("\n", trim($phpFpmServices)));

        foreach ($services as $service) {
            $service = trim($service);
            if (!empty($service)) {
                try {
                    $this->executor->execute("sudo systemctl restart {$service}");
                    $this->output->success("Restarted {$service}");
                } catch (\Exception $e) {
                    $this->output->error("Failed to restart {$service}: " . $e->getMessage());
                    throw $e;
                }
            }
        }
    }
}
