<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Exceptions\HealthCheckException;
use Shaf\LaravelDeployer\Services\CommandService;

/**
 * Health check action.
 * Performs server resource and endpoint health checks.
 *
 * Uses sensible defaults:
 * - Timeout: 10 seconds
 * - Expected status: 200
 * - Retries: 3
 * - Retry delay: 2 seconds
 */
class HealthCheckAction extends Action
{
    // Hardcoded sensible defaults (simplifies configuration)
    private const DEFAULT_TIMEOUT = 10;

    private const DEFAULT_EXPECTED_STATUS = 200;

    private const DEFAULT_RETRIES = 3;

    private const DEFAULT_RETRY_DELAY = 2;

    public function __construct(
        CommandService $cmd,
        DeploymentConfig $config
    ) {
        parent::__construct($cmd, $config);
    }

    /**
     * Perform complete health check (server resources + configured URL)
     */
    public function check(): bool
    {
        $this->cmd->task('health:check');

        $resourcesOk = $this->checkServerResources();
        $endpointOk = true;

        // Check health URL if configured
        if ($this->config->isHealthCheckEnabled()) {
            $endpointOk = $this->checkEndpoints([
                ['url' => $this->buildHealthCheckUrl(), 'status' => self::DEFAULT_EXPECTED_STATUS],
            ]);
        }

        if ($resourcesOk && $endpointOk) {
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
                "echo \"DISK_AVAIL:\$(df -h {$escapedPath} | tail -1 | awk '{print \$4}')\" && ".
                "echo \"MEM:\$(free | grep Mem | awk '{print int(\$3/\$2 * 100)}')\""
            );

            // Parse labeled output
            $diskUsage = 0;
            $diskAvailable = 'unknown';
            $memUsage = 0;

            foreach (explode("\n", $output) as $line) {
                if (str_starts_with($line, 'DISK:')) {
                    $diskUsage = (int) trim(substr($line, 5));
                } elseif (str_starts_with($line, 'DISK_AVAIL:')) {
                    $diskAvailable = trim(substr($line, 11));
                } elseif (str_starts_with($line, 'MEM:')) {
                    $memUsage = (int) trim(substr($line, 4));
                }
            }

            $this->cmd->info("Disk usage: {$diskUsage}%");

            if ($diskUsage > 90) {
                $this->cmd->error("⚠️  Disk usage is critically high: {$diskUsage}%");
                throw HealthCheckException::diskSpaceCritical($diskUsage, $diskAvailable);
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

    /**
     * Verify application health after deployment (with retries).
     * This runs POST-deployment to ensure the new release is working.
     */
    public function verifyDeployment(): bool
    {
        if (! $this->config->isHealthCheckEnabled()) {
            return true;
        }

        $this->cmd->task('health:verify');
        $this->cmd->info('Verifying deployment health...');

        $url = $this->buildHealthCheckUrl();
        $expectedStatus = self::DEFAULT_EXPECTED_STATUS;
        $timeout = self::DEFAULT_TIMEOUT;

        try {
            retry(
                times: self::DEFAULT_RETRIES,
                callback: function (int $attempt) use ($url, $timeout, $expectedStatus): int {
                    $this->cmd->info("  → Attempt {$attempt}/".self::DEFAULT_RETRIES.": Health check GET {$url}");
                    $startTime = microtime(true);

                    $statusCode = (int) trim($this->cmd->remote(
                        "curl -s -o /dev/null -w '%{http_code}' --max-time {$timeout} '{$url}'"
                    ));

                    $duration = (microtime(true) - $startTime) * 1000;

                    if ($statusCode !== $expectedStatus) {
                        $this->cmd->warning("  ✗ Health check returned {$statusCode}, expected {$expectedStatus}");
                        throw new \RuntimeException("Status code {$statusCode} != expected {$expectedStatus}");
                    }

                    $this->cmd->success(sprintf('Health check passed (%dms)', (int) $duration));

                    return $statusCode;
                },
                sleepMilliseconds: self::DEFAULT_RETRY_DELAY * 1000
            );

            $this->cmd->success("Health check passed ({$url})");

            return true;
        } catch (\Exception $e) {
            throw HealthCheckException::endpointFailed(
                $url,
                $expectedStatus,
                $e->getMessage()
            );
        }
    }

    /**
     * Build the full health check URL from config
     */
    private function buildHealthCheckUrl(): string
    {
        $url = $this->config->healthCheckUrl;

        // If it's already a full URL, return it
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        // Build URL from hostname
        $scheme = $this->config->environment->isProduction() ? 'https' : 'http';
        $host = $this->config->hostname;
        $path = ltrim($url, '/');

        return "{$scheme}://{$host}/{$path}";
    }
}
