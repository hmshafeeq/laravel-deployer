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
     * Each service is restarted independently so one failure doesn't block others
     */
    private function restartServices(): void
    {
        $this->cmd->info('Restarting services...');

        // Restart PHP-FPM
        try {
            $phpFpmService = trim($this->cmd->remote(
                'systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" | head -1 || echo ""'
            ));

            if (! empty($phpFpmService)) {
                $this->cmd->remote("sudo systemctl restart {$phpFpmService}");
                $this->cmd->info("  ✓ Restarted {$phpFpmService}");
            } else {
                $this->cmd->warning('  No running PHP-FPM service found');
            }
        } catch (\Exception $e) {
            $this->cmd->warning("  PHP-FPM restart failed: {$e->getMessage()}");
        }

        // Reload Nginx
        try {
            $this->cmd->remote('sudo systemctl reload nginx');
            $this->cmd->info('  ✓ Reloaded nginx');
        } catch (\Exception $e) {
            $this->cmd->warning("  Nginx reload failed: {$e->getMessage()}");
        }

        // Reload Supervisor
        try {
            $this->cmd->remote('sudo supervisorctl reread && sudo supervisorctl update');
            $this->cmd->info('  ✓ Reloaded supervisor');
        } catch (\Exception $e) {
            $this->cmd->warning("  Supervisor reload failed: {$e->getMessage()}");
        }

        $this->cmd->success('Service restart completed');
    }
}
