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
        $this->cmd->task('optimize');
        $this->cmd->info('Running post-deployment optimizations...');
        $this->cmd->newLine();

        $releasePath = $this->config->deployPath.'/current';
        $escapedPath = CommandService::escapePath($releasePath);
        $php = $this->config->phpBinary;

        // 1. Storage link (may fail if already exists)
        $this->createStorageLink($releasePath);

        // 2. Run optimize + queue:restart in a single batched call
        // Note: artisan optimize already runs config:cache, route:cache, view:cache, event:cache
        try {
            $this->cmd->runBatch([
                "{$php} {$escapedPath}/artisan optimize",
                "{$php} {$escapedPath}/artisan queue:restart",
            ]);
            $this->cmd->success('Application optimized and queues restarted');
        } catch (\Exception $e) {
            $this->cmd->warning('Optimization failed: '.$e->getMessage());
        }

        // 3. Restart services
        if (! $this->config->isLocal) {
            $this->restartServices();
        }

        $this->cmd->newLine();
        $this->cmd->success('✅ Optimization completed');
    }

    /**
     * Create storage symlink
     */
    private function createStorageLink(string $releasePath): void
    {
        try {
            $this->cmd->artisanStorageLink($releasePath);
            $this->cmd->success('Storage link created');
        } catch (\Exception $e) {
            $this->cmd->warning('Storage link creation failed (may already exist)');
        }
    }

    /**
     * Restart all configured services (PHP-FPM, Nginx, Supervisor)
     */
    private function restartServices(): void
    {
        $this->cmd->info('Restarting services...');

        try {
            // Detect PHP-FPM version first (can't batch this detection with restarts)
            $phpFpmService = trim($this->cmd->remote(
                'systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" | head -1 || echo ""'
            ));

            if (empty($phpFpmService)) {
                $this->cmd->warning('No running PHP-FPM service found');

                // Still restart nginx and supervisor
                $this->cmd->runBatch([
                    'sudo systemctl reload nginx',
                    'sudo supervisorctl reread && sudo supervisorctl update',
                ]);
            } else {
                // Batch all service restarts into a single SSH call
                $this->cmd->runBatch([
                    "sudo systemctl restart {$phpFpmService}",
                    'sudo systemctl reload nginx',
                    'sudo supervisorctl reread && sudo supervisorctl update',
                ]);
            }

            $this->cmd->success('Services restarted');
        } catch (\Exception $e) {
            $this->cmd->warning('Service restart failed: '.$e->getMessage());
        }
    }
}
