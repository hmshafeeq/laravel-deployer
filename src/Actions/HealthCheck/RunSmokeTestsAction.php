<?php

namespace Shaf\LaravelDeployer\Actions\HealthCheck;

use Shaf\LaravelDeployer\Support\Abstract\HealthCheckAction;

class RunSmokeTestsAction extends HealthCheckAction
{
    public function execute(string $appUrl): array
    {
        $this->writeln("🧪 Testing critical endpoints:");

        $endpoints = config('laravel-deployer.health_check.endpoints', [
            '/' => 'Home page',
            '/admin/login' => 'Admin login',
            '/user/login' => 'User login',
            '/health' => 'Health check',
        ]);

        $acceptableStatusCodes = config('laravel-deployer.health_check.acceptable_status_codes', [200, 302, 401]);

        $results = [];

        foreach ($endpoints as $endpoint => $description) {
            $url = rtrim($appUrl, '/') . $endpoint;
            $this->writeln("run curl -s -o /dev/null -w '%{http_code}' {$url} || echo 'FAILED'");
            $response = $this->run("curl -s -o /dev/null -w '%{http_code}' {$url} || echo 'FAILED'");
            $this->writeln($response);

            if (!in_array($response, array_map('strval', $acceptableStatusCodes))) {
                throw new \RuntimeException("Smoke test failed for {$endpoint} ({$description}). HTTP: {$response}");
            }

            $this->writeln("   ✅ {$endpoint} ({$description}) - HTTP {$response}");

            $results[$endpoint] = [
                'description' => $description,
                'status_code' => $response,
                'success' => true,
            ];
        }

        return $results;
    }
}
