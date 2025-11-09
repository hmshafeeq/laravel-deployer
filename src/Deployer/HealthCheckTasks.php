<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Concerns\ExecutesCommands;
use Shaf\LaravelDeployer\Constants\Timeouts;
use Shaf\LaravelDeployer\Exceptions\HealthCheckException;

class HealthCheckTasks extends BaseTaskRunner
{
    use ExecutesCommands;

    public function checkResources(): void
    {
        $this->task('health:check-resources', function () {
            $this->output->info("🔍 Checking server resources...");

            // Check disk space
            $diskUsage = $this->run("df -h {$this->getDeployPath()} | tail -1");

            $diskInfo = preg_split('/\s+/', trim($diskUsage));

            // Handle different df output formats (Linux vs macOS)
            $usedPercentIndex = count($diskInfo) === 6 ? 4 : 3;
            $availableIndex = count($diskInfo) === 6 ? 3 : 2;

            if (isset($diskInfo[$usedPercentIndex])) {
                $usedPercent = rtrim($diskInfo[$usedPercentIndex], '%');
                $available = $diskInfo[$availableIndex] ?? 'unknown';

                $this->output->info("💾 Disk Usage: {$diskInfo[$usedPercentIndex]} used, {$available} available");

                if ((int) $usedPercent > 90) {
                    throw HealthCheckException::diskSpaceCritical((int) $usedPercent, $available);
                }

                if ((int) $usedPercent > 80) {
                    $this->output->warning("Disk usage is high ({$usedPercent}%). Consider cleaning up old releases.");
                } else {
                    $this->output->success("Disk space OK");
                }
            }

            // Check memory usage
            $memInfo = $this->run('free -h | grep -E "^Mem:|^Swap:" || echo "Memory info unavailable"');

            if (!str_contains($memInfo, 'unavailable')) {
                $lines = explode("\n", trim($memInfo));
                foreach ($lines as $line) {
                    if (str_starts_with($line, 'Mem:')) {
                        $memParts = preg_split('/\s+/', $line);
                        $this->output->info("🧠 Memory: {$memParts[2]} used / {$memParts[1]} total ({$memParts[3]} available)");
                    }
                    if (str_starts_with($line, 'Swap:')) {
                        $swapParts = preg_split('/\s+/', $line);
                        if ($swapParts[1] !== '0B') {
                            $this->output->info("💾 Swap: {$swapParts[2]} used / {$swapParts[1]} total");
                        }
                    }
                }
            }

            $this->output->newLine();
        });
    }

    public function checkEndpoints(): void
    {
        $this->task('health:check-endpoints', function () {
            $currentPath = $this->getCurrentPath();

            $appUrl = trim($this->run("cd {$currentPath} && php artisan tinker --execute=\"echo config(\\\"app.url\\\");\""));

            $this->output->info("🔍 Running deployment health checks...");
            $this->output->newLine();

            // Check the dedicated health endpoint
            $healthUrl = rtrim($appUrl, '/') . '/health';

            $healthStatusCode = null;
            $healthResponse = null;

            for ($attempt = 1; $attempt <= Timeouts::MAX_HEALTH_CHECK_RETRIES; $attempt++) {
                $this->output->debug("Health check attempt {$attempt}/" . Timeouts::MAX_HEALTH_CHECK_RETRIES);

                try {
                    $healthResponse = $this->run("timeout " . Timeouts::HEALTH_CHECK . " curl -s --max-time 10 --connect-timeout 5 {$healthUrl}");
                    $healthStatusCode = $this->run("timeout " . Timeouts::HEALTH_CHECK . " curl -s --max-time 10 --connect-timeout 5 -o /dev/null -w '%{http_code}' {$healthUrl}");

                    if ($healthStatusCode === '200') {
                        break;
                    }

                    if ($attempt < Timeouts::MAX_HEALTH_CHECK_RETRIES) {
                        $this->output->warning("Health check failed (HTTP {$healthStatusCode}), retrying in " . Timeouts::HEALTH_CHECK_RETRY_DELAY . " seconds...");
                        sleep(Timeouts::HEALTH_CHECK_RETRY_DELAY);
                    }
                } catch (\Exception $e) {
                    if ($attempt < Timeouts::MAX_HEALTH_CHECK_RETRIES) {
                        $this->output->warning("Health check connection failed, retrying in " . Timeouts::HEALTH_CHECK_RETRY_DELAY . " seconds...");
                        sleep(Timeouts::HEALTH_CHECK_RETRY_DELAY);
                    } else {
                        throw HealthCheckException::timeout($healthUrl);
                    }
                }
            }

            if ($healthStatusCode !== '200') {
                throw HealthCheckException::endpointFailed($healthUrl, (int) $healthStatusCode, $healthResponse);
            }

            // Pretty print the health check JSON
            $this->output->info("📊 Health Status:");
            $prettyHealth = $this->run("echo '{$healthResponse}' | python3 -m json.tool 2>/dev/null || echo '{$healthResponse}'");
            $this->output->commandOutput($prettyHealth);
            $this->output->newLine();

            // Run smoke tests on critical endpoints
            $this->output->info("🧪 Testing critical endpoints:");

            $endpoints = [
                '/' => 'Home page',
                '/admin/login' => 'Admin login',
                '/user/login' => 'User login',
                '/health' => 'Health check',
            ];

            foreach ($endpoints as $endpoint => $description) {
                $url = rtrim($appUrl, '/') . $endpoint;
                $response = $this->run("curl -s -o /dev/null -w '%{http_code}' {$url} || echo 'FAILED'");

                if (!in_array($response, ['200', '302', '401'])) {
                    throw HealthCheckException::smokTestFailed($endpoint, $description, $response);
                }

                $this->output->success("{$endpoint} ({$description}) - HTTP {$response}");
            }

            $this->output->newLine();
            $this->output->success("All health checks passed!");
        });
    }
}
