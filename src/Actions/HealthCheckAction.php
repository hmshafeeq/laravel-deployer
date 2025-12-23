<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Exceptions\HealthCheckException;
use Shaf\LaravelDeployer\Services\CommandService;

/**
 * Health check action.
 * Performs server resource and endpoint health checks.
 */
class HealthCheckAction
{
    public function __construct(
        private CommandService $cmd,
        private DeploymentConfig $config
    ) {}

    /**
     * Perform complete health check
     */
    public function check(): bool
    {
        $this->cmd->task('health:check');

        $resourcesOk = $this->checkServerResources();
        $endpointsOk = true;

        // Check endpoints if configured
        if (! empty($this->config->healthCheckEndpoints)) {
            $endpointsOk = $this->checkEndpoints($this->config->healthCheckEndpoints);
        }

        if ($resourcesOk && $endpointsOk) {
            $this->cmd->success('All health checks passed');

            return true;
        }

        return false;
    }

    /**
     * Check server resources (disk space, memory)
     */
    public function checkServerResources(): bool
    {
        $this->cmd->info('Checking server resources...');

        try {
            // Batch disk and memory checks into a single SSH call with labeled output
            $escapedPath = CommandService::escapePath($this->config->deployPath);
            $output = $this->cmd->remote(
                "echo \"DISK:\$(df -h {$escapedPath} | tail -1 | awk '{print \$5}' | sed 's/%//')\" && ".
                "echo \"MEM:\$(free | grep Mem | awk '{print int(\$3/\$2 * 100)}')\""
            );

            // Parse labeled output
            $diskUsage = 0;
            $memUsage = 0;

            foreach (explode("\n", $output) as $line) {
                if (str_starts_with($line, 'DISK:')) {
                    $diskUsage = (int) trim(substr($line, 5));
                } elseif (str_starts_with($line, 'MEM:')) {
                    $memUsage = (int) trim(substr($line, 4));
                }
            }

            $this->cmd->info("Disk usage: {$diskUsage}%");

            if ($diskUsage > 90) {
                $this->cmd->error("⚠️  Disk usage is critically high: {$diskUsage}%");
                throw HealthCheckException::diskSpaceLow($diskUsage);
            }

            if ($diskUsage > 80) {
                $this->cmd->warning("⚠️  Disk usage is high: {$diskUsage}%");
            }

            $this->cmd->info("Memory usage: {$memUsage}%");

            if ($memUsage > 95) {
                $this->cmd->warning("⚠️  Memory usage is very high: {$memUsage}%");
            }

            $this->cmd->success('Server resources check passed');

            return true;

        } catch (\Exception $e) {
            $this->cmd->error('Server resources check failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Check HTTP endpoints
     */
    public function checkEndpoints(array $endpoints): bool
    {
        $this->cmd->info('Checking HTTP endpoints...');

        $allPassed = true;

        foreach ($endpoints as $endpoint) {
            try {
                $url = $endpoint['url'] ?? $endpoint;
                $expectedStatus = $endpoint['status'] ?? 200;

                $statusCode = $this->cmd->remote("curl -s -o /dev/null -w '%{http_code}' {$url}");
                $statusCode = (int) trim($statusCode);

                if ($statusCode === $expectedStatus) {
                    $this->cmd->success("✓ {$url} returned {$statusCode}");
                } else {
                    $this->cmd->error("✗ {$url} returned {$statusCode} (expected {$expectedStatus})");
                    $allPassed = false;
                }

            } catch (\Exception $e) {
                $this->cmd->error("✗ Failed to check {$url}: ".$e->getMessage());
                $allPassed = false;
            }
        }

        if ($allPassed) {
            $this->cmd->success('All endpoint checks passed');
        } else {
            $this->cmd->error('Some endpoint checks failed');
        }

        return $allPassed;
    }
}
