<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Services\CommandRetryService;

class HealthCheckTasks
{
    protected Deployer $deployer;
    protected CommandRetryService $retry;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
        $this->retry = new CommandRetryService();
    }

    public function checkResources(): void
    {
        $this->deployer->task('health:check-resources', function ($deployer) {
            $deployer->writeln("🔍 Checking server resources...");

            // Check disk space
            $deployer->writeln("run df -h {$deployer->getDeployPath()} | tail -1");
            $diskUsage = $deployer->run("df -h {$deployer->getDeployPath()} | tail -1");
            $deployer->writeln($diskUsage);

            $this->checkDiskSpace($diskUsage);
            $this->checkMemoryUsage();

            $deployer->writeln("");
        });
    }

    protected function checkDiskSpace(string $diskUsage): void
    {
        $diskInfo = preg_split('/\s+/', trim($diskUsage));

        // Handle different df output formats (Linux vs macOS)
        $usedPercentIndex = count($diskInfo) === 6 ? 4 : 3;
        $availableIndex = count($diskInfo) === 6 ? 3 : 2;

        if (isset($diskInfo[$usedPercentIndex])) {
            $usedPercent = rtrim($diskInfo[$usedPercentIndex], '%');
            $available = $diskInfo[$availableIndex] ?? 'unknown';

            $this->deployer->writeln("💾 Disk Usage: {$diskInfo[$usedPercentIndex]} used, {$available} available");

            $criticalThreshold = config('laravel-deployer.resources.disk.critical_threshold', 90);
            $warningThreshold = config('laravel-deployer.resources.disk.warning_threshold', 80);

            if ((int) $usedPercent > $criticalThreshold) {
                throw new \RuntimeException("❌ Disk space critical! {$usedPercent}% used. Please free up space before deployment.");
            }

            if ((int) $usedPercent > $warningThreshold) {
                $this->deployer->writeln("⚠️  Warning: Disk usage is high ({$usedPercent}%). Consider cleaning up old releases.", 'comment');
            } else {
                $this->deployer->writeln("✅ Disk space OK");
            }
        }
    }

    protected function checkMemoryUsage(): void
    {
        $this->deployer->writeln("run free -h | grep -E \"^Mem:|^Swap:\" || echo \"Memory info unavailable\"");
        $memInfo = $this->deployer->run('free -h | grep -E "^Mem:|^Swap:" || echo "Memory info unavailable"');

        if (!str_contains($memInfo, 'unavailable')) {
            $lines = explode("\n", trim($memInfo));
            foreach ($lines as $line) {
                $this->deployer->writeln($line);
                if (str_starts_with($line, 'Mem:')) {
                    $memParts = preg_split('/\s+/', $line);
                    $this->deployer->writeln("🧠 Memory: {$memParts[2]} used / {$memParts[1]} total ({$memParts[3]} available)");
                }
                if (str_starts_with($line, 'Swap:')) {
                    $swapParts = preg_split('/\s+/', $line);
                    if ($swapParts[1] !== '0B') {
                        $this->deployer->writeln("💾 Swap: {$swapParts[2]} used / {$swapParts[1]} total");
                    }
                }
            }
        }
    }

    public function checkEndpoints(): void
    {
        $this->deployer->task('health:check-endpoints', function ($deployer) {
            $currentPath = $deployer->getCurrentPath();

            $deployer->writeln("run cd {$currentPath} && php artisan tinker --execute=\"echo config(\\\"app.url\\\");\"");
            $appUrl = $deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo config(\\\"app.url\\\");\"");
            $deployer->writeln($appUrl);
            $appUrl = trim($appUrl);

            $deployer->writeln("🔍 Running deployment health checks...");
            $deployer->writeln("");

            $this->performHealthCheck($appUrl);
            $this->performSmokeTests($appUrl);

            $deployer->writeln("");
            $deployer->writeln("✅ All health checks passed!");
        });
    }

    protected function performHealthCheck(string $appUrl): void
    {
        $healthUrl = rtrim($appUrl, '/') . '/health';
        $maxRetries = config('laravel-deployer.health_check.max_retries', 3);
        $retryDelay = config('laravel-deployer.health_check.retry_delay', 5);
        $timeout = config('laravel-deployer.health_check.timeout', 30);
        $connectTimeout = config('laravel-deployer.health_check.connect_timeout', 5);

        $healthResponse = $this->retry->retry(
            callback: function ($attempt) use ($healthUrl, $timeout, $connectTimeout, $maxRetries) {
                $this->deployer->writeln("🔄 Health check attempt {$attempt}/{$maxRetries}...");

                $this->deployer->writeln("run timeout {$timeout} curl -s --max-time 10 --connect-timeout {$connectTimeout} {$healthUrl}");
                $response = $this->deployer->run("timeout {$timeout} curl -s --max-time 10 --connect-timeout {$connectTimeout} {$healthUrl}");
                $this->deployer->writeln($response);

                $this->deployer->writeln("run timeout {$timeout} curl -s --max-time 10 --connect-timeout {$connectTimeout} -o /dev/null -w '%{http_code}' {$healthUrl}");
                $statusCode = $this->deployer->run("timeout {$timeout} curl -s --max-time 10 --connect-timeout {$connectTimeout} -o /dev/null -w '%{http_code}' {$healthUrl}");
                $this->deployer->writeln($statusCode);

                if ($statusCode !== '200') {
                    throw new \RuntimeException("Health check failed with HTTP {$statusCode}");
                }

                return $response;
            },
            maxRetries: $maxRetries,
            delaySeconds: $retryDelay,
            onRetry: function ($attempt) {
                $this->deployer->writeln("⚠️  Health check failed, retrying in 5 seconds...", 'comment');
            }
        );

        // Pretty print the health check JSON
        $this->deployer->writeln("📊 Health Status:");
        $this->deployer->writeln("run echo '{$healthResponse}' | python3 -m json.tool 2>/dev/null || echo '{$healthResponse}'");
        $prettyHealth = $this->deployer->run("echo '{$healthResponse}' | python3 -m json.tool 2>/dev/null || echo '{$healthResponse}'");

        $lines = explode("\n", trim($prettyHealth));
        foreach ($lines as $line) {
            $this->deployer->writeln($line);
        }
        echo $prettyHealth . "\n";

        $this->deployer->writeln("");
    }

    protected function performSmokeTests(string $appUrl): void
    {
        $this->deployer->writeln("🧪 Testing critical endpoints:");

        $endpoints = config('laravel-deployer.health_check.endpoints', [
            '/' => 'Home page',
            '/admin/login' => 'Admin login',
            '/user/login' => 'User login',
            '/health' => 'Health check',
        ]);

        $acceptableStatusCodes = config('laravel-deployer.health_check.acceptable_status_codes', [200, 302, 401]);

        foreach ($endpoints as $endpoint => $description) {
            $url = rtrim($appUrl, '/') . $endpoint;
            $this->deployer->writeln("run curl -s -o /dev/null -w '%{http_code}' {$url} || echo 'FAILED'");
            $response = $this->deployer->run("curl -s -o /dev/null -w '%{http_code}' {$url} || echo 'FAILED'");
            $this->deployer->writeln($response);

            if (!in_array($response, array_map('strval', $acceptableStatusCodes))) {
                throw new \RuntimeException("Smoke test failed for {$endpoint} ({$description}). HTTP: {$response}");
            }

            $this->deployer->writeln("   ✅ {$endpoint} ({$description}) - HTTP {$response}");
        }
    }
}
