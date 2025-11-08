<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Actions\HealthCheck\CheckDiskSpaceAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\CheckMemoryUsageAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\CheckHealthEndpointAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\RunSmokeTestsAction;

class HealthCheckTasks
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
    }

    /**
     * Check server resources (disk space and memory)
     */
    public function checkResources(): void
    {
        $this->deployer->task('health:check-resources', function () {
            $this->deployer->writeln("🔍 Checking server resources...");

            CheckDiskSpaceAction::run($this->deployer);
            CheckMemoryUsageAction::run($this->deployer);

            $this->deployer->writeln("");
        });
    }

    /**
     * Check application endpoints (health check and smoke tests)
     */
    public function checkEndpoints(): void
    {
        $this->deployer->task('health:check-endpoints', function ($deployer) {
            $appUrl = $this->getApplicationUrl();

            $deployer->writeln("🔍 Running deployment health checks...");
            $deployer->writeln("");

            CheckHealthEndpointAction::run($this->deployer, null, $appUrl);
            RunSmokeTestsAction::run($this->deployer, $appUrl);

            $deployer->writeln("");
            $deployer->writeln("✅ All health checks passed!");
        });
    }

    /**
     * Get the application URL from the deployed application
     */
    protected function getApplicationUrl(): string
    {
        $currentPath = $this->deployer->getCurrentPath();

        $this->deployer->writeln("run cd {$currentPath} && php artisan tinker --execute=\"echo config(\\\"app.url\\\");\"");
        $appUrl = $this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo config(\\\"app.url\\\");\"");
        $this->deployer->writeln($appUrl);

        return trim($appUrl);
    }

    /**
     * Run disk space check
     */
    public function checkDiskSpace(): array
    {
        return CheckDiskSpaceAction::run($this->deployer);
    }

    /**
     * Run memory usage check
     */
    public function checkMemoryUsage(): array
    {
        return CheckMemoryUsageAction::run($this->deployer);
    }

    /**
     * Run health endpoint check
     */
    public function checkHealthEndpoint(string $appUrl): string
    {
        return CheckHealthEndpointAction::run($this->deployer, null, $appUrl);
    }

    /**
     * Run smoke tests
     */
    public function runSmokeTests(string $appUrl): array
    {
        return RunSmokeTestsAction::run($this->deployer, $appUrl);
    }
}
