<?php

namespace Shaf\LaravelDeployer\Actions\Health;

use Shaf\LaravelDeployer\Actions\AbstractAction;

class CheckEndpointsAction extends AbstractAction
{
    public function execute(): void
    {
        $currentPath = $this->deployer->getCurrentPath();

        $this->deployer->writeln("run cd {$currentPath} && php artisan tinker --execute=\"echo config(\\\"app.url\\\");\"");
        $appUrl = $this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo config(\\\"app.url\\\");\"");
        $this->deployer->writeln($appUrl);
        $appUrl = trim($appUrl);

        $this->deployer->writeln("🔍 Running deployment health checks...");
        $this->deployer->writeln("");

        // First, check the dedicated health endpoint with detailed output
        $healthUrl = rtrim($appUrl, '/') . '/health';

        // Health check with timeout, retry logic, and proper error handling
        $maxRetries = 3;
        $healthStatusCode = null;
        $healthResponse = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->deployer->writeln("🔄 Health check attempt {$attempt}/{$maxRetries}...");

            try {
                $this->deployer->writeln("run timeout 30 curl -s --max-time 10 --connect-timeout 5 {$healthUrl}");
                $healthResponse = $this->deployer->run("timeout 30 curl -s --max-time 10 --connect-timeout 5 {$healthUrl}");
                $this->deployer->writeln($healthResponse);

                $this->deployer->writeln("run timeout 30 curl -s --max-time 10 --connect-timeout 5 -o /dev/null -w '%{http_code}' {$healthUrl}");
                $healthStatusCode = $this->deployer->run("timeout 30 curl -s --max-time 10 --connect-timeout 5 -o /dev/null -w '%{http_code}' {$healthUrl}");
                $this->deployer->writeln($healthStatusCode);

                if ($healthStatusCode === '200') {
                    break; // Success, exit retry loop
                }

                if ($attempt < $maxRetries) {
                    $this->deployer->writeln("⚠️  Health check failed (HTTP {$healthStatusCode}), retrying in 5 seconds...", 'comment');
                    sleep(5);
                }
            } catch (\Exception $e) {
                if ($attempt < $maxRetries) {
                    $this->deployer->writeln("⚠️  Health check connection failed, retrying in 5 seconds...", 'comment');
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
        $this->deployer->writeln("📊 Health Status:");
        $this->deployer->writeln("run echo '{$healthResponse}' | python3 -m json.tool 2>/dev/null || echo '{$healthResponse}'");
        $prettyHealth = $this->deployer->run("echo '{$healthResponse}' | python3 -m json.tool 2>/dev/null || echo '{$healthResponse}'");

        $lines = explode("\n", trim($prettyHealth));
        foreach ($lines as $line) {
            $this->deployer->writeln($line);
        }
        echo $prettyHealth . "\n";

        $this->deployer->writeln("");

        // Then run smoke tests on all critical endpoints
        $this->deployer->writeln("🧪 Testing critical endpoints:");

        $endpoints = [
            '/' => 'Home page',
            '/admin/login' => 'Admin login',
            '/user/login' => 'User login',
            '/health' => 'Health check',
        ];

        foreach ($endpoints as $endpoint => $description) {
            $url = rtrim($appUrl, '/') . $endpoint;
            $this->deployer->writeln("run curl -s -o /dev/null -w '%{http_code}' {$url} || echo 'FAILED'");
            $response = $this->deployer->run("curl -s -o /dev/null -w '%{http_code}' {$url} || echo 'FAILED'");
            $this->deployer->writeln($response);

            if (!in_array($response, ['200', '302', '401'])) { // Allow redirects and auth pages
                throw new \RuntimeException("Smoke test failed for {$endpoint} ({$description}). HTTP: {$response}");
            }

            $this->deployer->writeln("   ✅ {$endpoint} ({$description}) - HTTP {$response}");
        }

        $this->deployer->writeln("");
        $this->deployer->writeln("✅ All health checks passed!");
    }

    public function getName(): string
    {
        return 'health:check_endpoints';
    }
}
