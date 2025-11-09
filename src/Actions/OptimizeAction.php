<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\CommandService;

/**
 * Optimization action.
 * Handles cache clearing, optimization, and service restarts.
 */
class OptimizeAction
{
    public function __construct(
        private CommandService $cmd,
        private DeploymentConfig $config
    ) {}

    /**
     * Execute complete optimization workflow
     */
    public function execute(): void
    {
        $this->cmd->task("optimize");
        $this->cmd->info("Running post-deployment optimizations...");
        $this->cmd->newLine();

        $releasePath = $this->config->deployPath . "/current";

        // 1. Storage link
        $this->createStorageLink($releasePath);

        // 2. Clear and cache configuration
        $this->optimizeConfiguration($releasePath);

        // 3. Clear and cache views
        $this->optimizeViews($releasePath);

        // 4. Clear and cache routes
        $this->optimizeRoutes($releasePath);

        // 5. Run optimize command
        $this->optimizeApplication($releasePath);

        // 6. Restart queue workers
        $this->restartQueueWorkers($releasePath);

        // 7. Restart services
        if (!$this->config->isLocal) {
            $this->restartPhpFpm();
            $this->reloadNginx();
            $this->reloadSupervisor();
        }

        $this->cmd->newLine();
        $this->cmd->success("✅ Optimization completed");
    }

    /**
     * Create storage symlink
     */
    private function createStorageLink(string $releasePath): void
    {
        try {
            $this->cmd->artisanStorageLink($releasePath);
            $this->cmd->success("Storage link created");
        } catch (\Exception $e) {
            $this->cmd->warning("Storage link creation failed (may already exist)");
        }
    }

    /**
     * Optimize configuration
     */
    private function optimizeConfiguration(string $releasePath): void
    {
        try {
            $this->cmd->artisanConfigCache($releasePath);
            $this->cmd->success("Configuration cached");
        } catch (\Exception $e) {
            $this->cmd->warning("Configuration caching failed: " . $e->getMessage());
        }
    }

    /**
     * Optimize views
     */
    private function optimizeViews(string $releasePath): void
    {
        try {
            $this->cmd->artisanViewCache($releasePath);
            $this->cmd->success("Views cached");
        } catch (\Exception $e) {
            $this->cmd->warning("View caching failed: " . $e->getMessage());
        }
    }

    /**
     * Optimize routes
     */
    private function optimizeRoutes(string $releasePath): void
    {
        try {
            $this->cmd->artisanRouteCache($releasePath);
            $this->cmd->success("Routes cached");
        } catch (\Exception $e) {
            $this->cmd->warning("Route caching failed: " . $e->getMessage());
        }
    }

    /**
     * Run optimize command
     */
    private function optimizeApplication(string $releasePath): void
    {
        try {
            $this->cmd->artisanOptimize($releasePath);
            $this->cmd->success("Application optimized");
        } catch (\Exception $e) {
            $this->cmd->warning("Application optimization failed: " . $e->getMessage());
        }
    }

    /**
     * Restart queue workers
     */
    private function restartQueueWorkers(string $releasePath): void
    {
        try {
            $this->cmd->artisanQueueRestart($releasePath);
            $this->cmd->success("Queue workers restarted");
        } catch (\Exception $e) {
            $this->cmd->warning("Queue restart failed: " . $e->getMessage());
        }
    }

    /**
     * Restart PHP-FPM service
     */
    private function restartPhpFpm(): void
    {
        try {
            $this->cmd->info("Restarting PHP-FPM...");

            // Detect all running PHP-FPM services
            $phpFpmServices = $this->cmd->remote('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""');

            if (!empty(trim($phpFpmServices))) {
                $services = array_filter(explode("\n", trim($phpFpmServices)));

                foreach ($services as $service) {
                    $service = trim($service);
                    if (!empty($service)) {
                        $this->cmd->remote("sudo systemctl restart {$service}");
                        $this->cmd->success("Restarted {$service}");
                    }
                }
            } else {
                $this->cmd->warning("No running PHP-FPM service found");
            }
        } catch (\Exception $e) {
            $this->cmd->warning("PHP-FPM restart failed: " . $e->getMessage());
        }
    }

    /**
     * Reload Nginx
     */
    private function reloadNginx(): void
    {
        try {
            $this->cmd->info("Reloading Nginx...");
            $this->cmd->remote("sudo systemctl reload nginx");
            $this->cmd->success("Nginx reloaded");
        } catch (\Exception $e) {
            $this->cmd->warning("Nginx reload failed: " . $e->getMessage());
        }
    }

    /**
     * Reload Supervisor
     */
    private function reloadSupervisor(): void
    {
        try {
            $this->cmd->info("Reloading Supervisor...");
            $this->cmd->remote("sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl restart all");
            $this->cmd->success("Supervisor reloaded");
        } catch (\Exception $e) {
            $this->cmd->warning("Supervisor reload failed: " . $e->getMessage());
        }
    }
}
