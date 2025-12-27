<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\CommandService;

/**
 * Optimization action.
 * Handles cache clearing, optimization, and service restarts.
 */
class OptimizeAction extends Action
{
    public function __construct(
        CommandService $cmd,
        DeploymentConfig $config
    ) {
        parent::__construct($cmd, $config);
    }

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
        $this->restartPhpFpm();

        // Reload Nginx
        $this->restartNginx();

        // Reload Supervisor
        $this->restartSupervisor();

        // Reset OPcache if available
        $this->resetOpcache();

        $this->cmd->success('Service restart completed');
    }

    /**
     * Restart PHP-FPM service with detailed error context
     */
    private function restartPhpFpm(): void
    {
        try {
            $this->cmd->restartPhpFpm();
        } catch (\Exception $e) {
            $this->showServiceError('PHP-FPM', $e->getMessage(), [
                'Check if PHP-FPM is installed: dpkg -l | grep php-fpm',
                'Check service status: sudo systemctl status php*-fpm',
                'Check logs: sudo journalctl -u php*-fpm -n 20',
            ]);
        }
    }

    /**
     * Restart Nginx with detailed error context
     */
    private function restartNginx(): void
    {
        try {
            $this->cmd->remote('sudo systemctl reload nginx');
            $this->cmd->info('  ✓ Reloaded nginx');
        } catch (\Exception $e) {
            $this->showServiceError('Nginx', $e->getMessage(), [
                'Test config: sudo nginx -t',
                'Check status: sudo systemctl status nginx',
                'Check logs: sudo tail -20 /var/log/nginx/error.log',
            ]);
        }
    }

    /**
     * Restart Supervisor with detailed error context
     */
    private function restartSupervisor(): void
    {
        try {
            $this->cmd->remote('sudo supervisorctl reread && sudo supervisorctl update');
            $this->cmd->info('  ✓ Reloaded supervisor');
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // Provide specific tips based on common errors
            $tips = ['Check if supervisor is installed: which supervisorctl'];

            if (str_contains($errorMessage, 'no such file') || str_contains($errorMessage, 'sock')) {
                $tips[] = 'Supervisor may not be running. Try: sudo systemctl start supervisor';
                $tips[] = 'Install supervisor: sudo apt install supervisor';
            } elseif (str_contains($errorMessage, 'permission') || str_contains($errorMessage, 'Permission')) {
                $tips[] = 'Check sudo permissions for the deploy user';
                $tips[] = 'Add to sudoers: deploy ALL=(ALL) NOPASSWD: /usr/bin/supervisorctl';
            }

            $tips[] = 'Check status: sudo systemctl status supervisor';

            $this->showServiceError('Supervisor', $errorMessage, $tips);
        }
    }

    /**
     * Reset OPcache via CLI or HTTP endpoint
     */
    private function resetOpcache(): void
    {
        // First try CLI reset
        try {
            $result = $this->cmd->remote(
                "{$this->config->phpBinary} -r \"if (function_exists('opcache_reset')) { opcache_reset(); echo 'reset'; } else { echo 'unavailable'; }\" 2>/dev/null || echo 'error'"
            );

            $result = trim($result);

            if ($result === 'reset') {
                $this->cmd->info('  ✓ Reset OPcache (CLI)');

                return;
            }

            if ($result === 'unavailable') {
                // OPcache not available in CLI, which is normal
                // It will be reset via PHP-FPM restart anyway
                return;
            }
        } catch (\Exception) {
            // CLI reset failed, not critical
        }

        // Note: PHP-FPM restart already clears OPcache for web requests
        // CLI opcache is separate from FPM opcache
    }

    /**
     * Display a service error with helpful tips
     *
     * @param  array<string>  $tips
     */
    private function showServiceError(string $service, string $errorMessage, array $tips): void
    {
        // Extract just the relevant part of the error message
        $shortError = $this->extractRelevantError($errorMessage);

        $this->cmd->warning("  ⚠  {$service} reload failed: {$shortError}");

        foreach ($tips as $tip) {
            $this->cmd->comment("    Tip: {$tip}");
        }
    }

    /**
     * Extract the most relevant part of an error message
     */
    private function extractRelevantError(string $message): string
    {
        // Remove SSH wrapper text
        $message = preg_replace('/^.*?:\s*/', '', $message);

        // Truncate very long messages
        if (strlen($message) > 80) {
            $message = substr($message, 0, 77).'...';
        }

        return $message ?: 'Unknown error';
    }
}
