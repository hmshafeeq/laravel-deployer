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
     * Restart all configured services (PHP-FPM, Nginx, Supervisor) in a single SSH call.
     * Uses subshells with || true so one failure doesn't block others.
     */
    private function restartServices(): void
    {
        $this->cmd->info('Restarting services...');

        // Detect all running PHP-FPM versions
        $phpFpmOutput = trim($this->cmd->remote(
            'systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""'
        ));

        $phpFpmServices = array_filter(array_map('trim', explode("\n", $phpFpmOutput)));

        // Build batched command for all service restarts
        // Each command is wrapped in (cmd || true) so failures don't stop the batch
        $commands = [];

        foreach ($phpFpmServices as $service) {
            $marker = strtoupper(str_replace(['.', '-'], '_', $service));
            $commands[] = "(sudo systemctl restart {$service} && echo '{$marker}_OK' || echo '{$marker}_FAIL')";
        }

        $commands[] = "(sudo systemctl reload nginx && echo 'NGINX_OK' || echo 'NGINX_FAIL')";
        $commands[] = "(sudo supervisorctl reread && sudo supervisorctl update && echo 'SUPERVISOR_OK' || echo 'SUPERVISOR_FAIL')";
        $commands[] = "({$this->config->phpBinary} -r \"if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPCACHE_OK'; } else { echo 'OPCACHE_SKIP'; }\" 2>/dev/null || echo 'OPCACHE_FAIL')";

        // Execute all in single SSH call
        $output = $this->cmd->remote(implode(' ; ', $commands));

        // Parse results and show status for each PHP-FPM service
        foreach ($phpFpmServices as $service) {
            $marker = strtoupper(str_replace(['.', '-'], '_', $service));
            if (str_contains($output, "{$marker}_OK")) {
                $this->cmd->info("  ✓ Restarted {$service}");
            } else {
                $this->cmd->warning("  ⚠  {$service} restart failed");
            }
        }

        if (str_contains($output, 'NGINX_OK')) {
            $this->cmd->info('  ✓ Reloaded nginx');
        } else {
            $this->cmd->warning('  ⚠  Nginx reload failed');
        }

        if (str_contains($output, 'SUPERVISOR_OK')) {
            $this->cmd->info('  ✓ Reloaded supervisor');
        } else {
            $this->cmd->warning('  ⚠  Supervisor reload failed');
        }

        if (str_contains($output, 'OPCACHE_OK')) {
            $this->cmd->info('  ✓ Reset OPcache (CLI)');
        }

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
