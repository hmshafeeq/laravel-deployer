<?php

namespace Shaf\LaravelDeployer\Deployer;

class HealthCheckTasks
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
    }

    public function checkResources(): void
    {
        $this->deployer->task('health:check-resources', function ($deployer) {
            $deployer->writeln("🔍 Checking server resources...");

            // Check disk space
            $deployer->writeln("run df -h {$deployer->getDeployPath()} | tail -1");
            $diskUsage = $deployer->run("df -h {$deployer->getDeployPath()} | tail -1");
            $deployer->writeln($diskUsage);

            $diskInfo = preg_split('/\s+/', trim($diskUsage));

            // Handle different df output formats (Linux vs macOS)
            $usedPercentIndex = count($diskInfo) === 6 ? 4 : 3;
            $availableIndex = count($diskInfo) === 6 ? 3 : 2;

            if (isset($diskInfo[$usedPercentIndex])) {
                $usedPercent = rtrim($diskInfo[$usedPercentIndex], '%');
                $available = $diskInfo[$availableIndex] ?? 'unknown';

                $deployer->writeln("💾 Disk Usage: {$diskInfo[$usedPercentIndex]} used, {$available} available");

                if ((int) $usedPercent > 90) {
                    throw new \RuntimeException("❌ Disk space critical! {$usedPercent}% used. Please free up space before deployment.");
                }

                if ((int) $usedPercent > 80) {
                    $deployer->writeln("⚠️  Warning: Disk usage is high ({$usedPercent}%). Consider cleaning up old releases.", 'comment');
                } else {
                    $deployer->writeln("✅ Disk space OK");
                }
            }

            // Check memory usage
            $deployer->writeln("run free -h | grep -E \"^Mem:|^Swap:\" || echo \"Memory info unavailable\"");
            $memInfo = $deployer->run('free -h | grep -E "^Mem:|^Swap:" || echo "Memory info unavailable"');

            if (!str_contains($memInfo, 'unavailable')) {
                $lines = explode("\n", trim($memInfo));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                    if (str_starts_with($line, 'Mem:')) {
                        $memParts = preg_split('/\s+/', $line);
                        $deployer->writeln("🧠 Memory: {$memParts[2]} used / {$memParts[1]} total ({$memParts[3]} available)");
                    }
                    if (str_starts_with($line, 'Swap:')) {
                        $swapParts = preg_split('/\s+/', $line);
                        if ($swapParts[1] !== '0B') {
                            $deployer->writeln("💾 Swap: {$swapParts[2]} used / {$swapParts[1]} total");
                        }
                    }
                }
            }

            $deployer->writeln("");
        });
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

            // First, check the dedicated health endpoint with detailed output
            $healthUrl = rtrim($appUrl, '/') . '/health';

            // Health check with timeout, retry logic, and proper error handling
            $maxRetries = 3;
            $healthStatusCode = null;
            $healthResponse = null;

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                $deployer->writeln("🔄 Health check attempt {$attempt}/{$maxRetries}...");

                try {
                    $deployer->writeln("run timeout 30 curl -s --max-time 10 --connect-timeout 5 {$healthUrl}");
                    $healthResponse = $deployer->run("timeout 30 curl -s --max-time 10 --connect-timeout 5 {$healthUrl}");
                    $deployer->writeln($healthResponse);

                    $deployer->writeln("run timeout 30 curl -s --max-time 10 --connect-timeout 5 -o /dev/null -w '%{http_code}' {$healthUrl}");
                    $healthStatusCode = $deployer->run("timeout 30 curl -s --max-time 10 --connect-timeout 5 -o /dev/null -w '%{http_code}' {$healthUrl}");
                    $deployer->writeln($healthStatusCode);

                    if ($healthStatusCode === '200') {
                        break; // Success, exit retry loop
                    }

                    if ($attempt < $maxRetries) {
                        $deployer->writeln("⚠️  Health check failed (HTTP {$healthStatusCode}), retrying in 5 seconds...", 'comment');
                        sleep(5);
                    }
                } catch (\Exception $e) {
                    if ($attempt < $maxRetries) {
                        $deployer->writeln("⚠️  Health check connection failed, retrying in 5 seconds...", 'comment');
                        sleep(5);
                    } else {
                        throw new \RuntimeException("Health endpoint connection failed after {$maxRetries} attempts: " . $e->getMessage());
                    }
                }
            }

            if ($healthStatusCode !== '200') {
                throw new \RuntimeException("Health endpoint failed after {$maxRetries} attempts. Final HTTP response: {$healthStatusCode}. Response body: {$healthResponse}");
            }

            // Pretty print the health check JSON
            $deployer->writeln("📊 Health Status:");
            $deployer->writeln("run echo '{$healthResponse}' | python3 -m json.tool 2>/dev/null || echo '{$healthResponse}'");
            $prettyHealth = $deployer->run("echo '{$healthResponse}' | python3 -m json.tool 2>/dev/null || echo '{$healthResponse}'");

            $lines = explode("\n", trim($prettyHealth));
            foreach ($lines as $line) {
                $deployer->writeln($line);
            }
            echo $prettyHealth . "\n";

            $deployer->writeln("");

            // Then run smoke tests on all critical endpoints
            $deployer->writeln("🧪 Testing critical endpoints:");

            $endpoints = [
                '/' => 'Home page',
                '/admin/login' => 'Admin login',
                '/user/login' => 'User login',
                '/health' => 'Health check',
            ];

            foreach ($endpoints as $endpoint => $description) {
                $url = rtrim($appUrl, '/') . $endpoint;
                $deployer->writeln("run curl -s -o /dev/null -w '%{http_code}' {$url} || echo 'FAILED'");
                $response = $deployer->run("curl -s -o /dev/null -w '%{http_code}' {$url} || echo 'FAILED'");
                $deployer->writeln($response);

                if (!in_array($response, ['200', '302', '401'])) { // Allow redirects and auth pages
                    throw new \RuntimeException("Smoke test failed for {$endpoint} ({$description}). HTTP: {$response}");
                }

                $deployer->writeln("   ✅ {$endpoint} ({$description}) - HTTP {$response}");
            }

            $deployer->writeln("");
            $deployer->writeln("✅ All health checks passed!");
        });
    }
}
