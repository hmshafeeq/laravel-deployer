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
    /** @var array<string> Post-deploy commands to run after service restart */
    private array $postDeployCommands = [];

    public function __construct(
        CommandService $cmd,
        DeploymentConfig $config
    ) {
        parent::__construct($cmd, $config);
    }

    /**
     * Set post-deploy commands to run after service restart.
     * These commands run AFTER services restart (fresh OPcache) but BEFORE artisan optimize.
     *
     * @param  array<string>  $commands  Commands to execute
     */
    public function setPostDeployCommands(array $commands): self
    {
        $this->postDeployCommands = $commands;

        return $this;
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

        // 1. Restart services FIRST to clear OPcache before running optimize
        if (! $this->config->isLocal) {
            $this->restartServices();
            $this->cmd->newLine();
        }

        // 2. Run post-deploy commands with fresh OPcache (after service restart)
        if (! empty($this->postDeployCommands)) {
            $this->runPostDeployCommands($releasePath);
            $this->cmd->newLine();
        }

        // 3. Run optimize + queue:restart with fresh OPcache
        // Note: artisan optimize already runs config:cache, route:cache, view:cache, event:cache
        // Note: storage:link is now run in DeployAction before symlinking (not here)
        $this->cmd->info('Running optimization with fresh OPcache...');
        try {
            $this->cmd->runBatch([
                "{$php} {$escapedPath}/artisan optimize",
                "{$php} {$escapedPath}/artisan queue:restart",
            ]);
            $this->cmd->success('Application optimized and queues restarted');
        } catch (\Exception $e) {
            $this->cmd->warning('Optimization failed: '.$e->getMessage());
        }

        $this->cmd->newLine();
        $this->cmd->success('✅ Optimization completed');
    }

    /**
     * Run post-deploy commands after service restart.
     * These commands benefit from fresh OPcache since PHP-FPM has been restarted.
     * Commands run in the CURRENT release path (after symlink switch).
     */
    private function runPostDeployCommands(string $releasePath): void
    {
        $this->cmd->info('Running post-deploy commands (with fresh OPcache)...');

        $phpBinary = $this->config->phpBinary;
        $artisanPath = "{$releasePath}/artisan";
        $escapedReleasePath = CommandService::escapePath($releasePath);

        foreach ($this->postDeployCommands as $command) {
            if ($this->isArtisanShortcut($command)) {
                // Artisan shortcut (e.g., "filament:optimize") - wrap with php artisan
                $fullCommand = "{$phpBinary} {$artisanPath} {$command}";
                $displayCmd = "artisan {$command}";
            } else {
                // Full command - run as-is (e.g., "php artisan migrate", "npm run build")
                $fullCommand = "cd {$escapedReleasePath} && {$command}";
                $displayCmd = $command;
            }

            $this->cmd->info("  → {$displayCmd}");

            try {
                $this->cmd->remoteWithOutput($fullCommand);
            } catch (\Exception $e) {
                // Post-deploy command failures are warnings, not fatal errors
                $this->cmd->warning("  ⚠ Command failed: {$e->getMessage()}");
            }
        }

        $this->cmd->success('Post-deploy commands completed');
    }

    /**
     * Check if command is an artisan shortcut (e.g., "config:cache")
     * vs a full shell command (e.g., "php artisan config:cache", "npm run build")
     */
    private function isArtisanShortcut(string $command): bool
    {
        // If command contains spaces, it's likely a full command
        // Artisan shortcuts are single words like "config:cache", "route:cache"
        if (str_contains($command, ' ')) {
            return false;
        }

        // Artisan commands typically contain a colon (namespace:command)
        // or are simple commands like "migrate", "optimize"
        return true;
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
        // Supervisor: if reload fails, probe with supervisord to get the REAL error (config/path issues)
        $commands[] = "(sudo supervisorctl reread && sudo supervisorctl update && echo 'SUPERVISOR_OK' || { echo 'SUPERVISOR_PROBE_START'; timeout 5 sudo supervisord -n -c /etc/supervisor/supervisord.conf 2>&1 | head -10 || true; echo 'SUPERVISOR_PROBE_END'; echo 'SUPERVISOR_FAIL'; })";
        // Note: PHP-FPM restart already clears FPM OPcache (the one that matters for web requests)
        // CLI OPcache is separate and doesn't affect web traffic, so no need to reset it here

        // Execute all in single SSH call
        $output = $this->cmd->remote(implode(' ; ', $commands));

        // Parse results and show status for each PHP-FPM service
        foreach ($phpFpmServices as $service) {
            $marker = strtoupper(str_replace(['.', '-'], '_', $service));
            if (str_contains($output, "{$marker}_OK")) {
                $this->cmd->info("  ✓ Restarted {$service}");
            } else {
                // Normalize service name for config check (e.g., 'php8.3-fpm' -> 'php-fpm')
                $normalizedService = preg_replace('/^php[0-9.]*-fpm$/', 'php-fpm', $service);
                if (in_array($normalizedService, $this->config->requiredServices, true)) {
                    throw new \RuntimeException("Required service {$service} failed to restart");
                }
                $this->cmd->warning("  ⚠  {$service} restart failed");
            }
        }

        if (str_contains($output, 'NGINX_OK')) {
            $this->cmd->info('  ✓ Reloaded nginx');
        } else {
            if (in_array('nginx', $this->config->requiredServices, true)) {
                throw new \RuntimeException('Required service nginx failed to reload');
            }
            $this->cmd->warning('  ⚠  Nginx reload failed');
        }

        if (str_contains($output, 'SUPERVISOR_OK')) {
            $this->cmd->info('  ✓ Reloaded supervisor');
        } else {
            // Extract the actual error from supervisord probe
            $supervisorError = $this->extractSupervisorError($output);

            if (in_array('supervisor', $this->config->requiredServices, true)) {
                $errorMsg = 'Required service supervisor failed to reload';
                if ($supervisorError) {
                    $errorMsg .= ": {$supervisorError}";
                }
                throw new \RuntimeException($errorMsg);
            }

            // Show warning with actual error details
            if ($supervisorError) {
                $this->cmd->warning("  ⚠  Supervisor reload failed: {$supervisorError}");
                $this->showSupervisorTips($supervisorError);
            } else {
                $this->cmd->warning('  ⚠  Supervisor reload failed');
                $this->cmd->comment('    Tip: Check if supervisor is running: sudo systemctl status supervisor');
            }
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

    /**
     * Extract the actual supervisor error from the probe output.
     * The probe runs `supervisord -n -c /etc/supervisor/supervisord.conf` to get real config errors.
     */
    private function extractSupervisorError(string $output): ?string
    {
        // Look for the probe output between markers
        if (preg_match('/SUPERVISOR_PROBE_START\s*(.*?)\s*SUPERVISOR_PROBE_END/s', $output, $matches)) {
            $probeOutput = trim($matches[1]);

            if (empty($probeOutput)) {
                return null;
            }

            // Look for specific error patterns and extract meaningful message
            // Pattern: "Error: The directory named as part of the path /path/to/file does not exist"
            if (preg_match("/Error:\s*(.+?)(?:\s*\(file:|$)/s", $probeOutput, $errorMatch)) {
                $error = trim($errorMatch[1]);
                // Clean up the error message
                $error = preg_replace('/\s+/', ' ', $error);

                return $error;
            }

            // Pattern: "unix:///var/run/supervisor.sock no such file"
            if (str_contains($probeOutput, 'no such file')) {
                return 'Supervisor socket not found - supervisor daemon not running';
            }

            // Pattern: FileNotFoundError from Python
            if (str_contains($probeOutput, 'FileNotFoundError')) {
                return 'Supervisor daemon not responding - may need restart';
            }

            // Return first meaningful line if no specific pattern matched
            $lines = array_filter(explode("\n", $probeOutput), fn ($line) => ! empty(trim($line)));
            if (! empty($lines)) {
                $firstLine = trim(reset($lines));
                if (strlen($firstLine) > 100) {
                    $firstLine = substr($firstLine, 0, 97).'...';
                }

                return $firstLine;
            }
        }

        // Fallback: check for common errors in the raw output
        if (str_contains($output, 'unix:///var/run/supervisor.sock no such file')) {
            return 'Supervisor socket not found - supervisor daemon not running';
        }

        return null;
    }

    /**
     * Show contextual troubleshooting tips based on the supervisor error.
     */
    private function showSupervisorTips(string $error): void
    {
        $tips = [];

        // Missing directory error
        if (str_contains($error, 'directory') && str_contains($error, 'does not exist')) {
            // Try to extract the path
            if (preg_match('/path\s+(\S+)/', $error, $pathMatch)) {
                $path = $pathMatch[1];
                $dir = dirname($path);
                $tips[] = "Create the missing directory: sudo mkdir -p {$dir}";
            } else {
                $tips[] = 'Create the missing log directory referenced in supervisor config';
            }
            $tips[] = 'Check your supervisor config files in /etc/supervisor/conf.d/';
            $tips[] = 'Then restart supervisor: sudo systemctl restart supervisor';
        }
        // Socket not found - daemon not running
        elseif (str_contains($error, 'socket not found') || str_contains($error, 'not running')) {
            $tips[] = 'Start supervisor: sudo systemctl start supervisor';
            $tips[] = 'Enable on boot: sudo systemctl enable supervisor';
            $tips[] = 'If still failing, check: sudo journalctl -u supervisor -n 20';
        }
        // Generic fallback
        else {
            $tips[] = 'Check supervisor status: sudo systemctl status supervisor';
            $tips[] = 'View supervisor logs: sudo journalctl -u supervisor -n 20';
            $tips[] = 'Test config: sudo supervisord -n -c /etc/supervisor/supervisord.conf';
        }

        foreach ($tips as $tip) {
            $this->cmd->comment("    → {$tip}");
        }
    }
}
