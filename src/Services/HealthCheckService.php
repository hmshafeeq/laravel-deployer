<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Actions\HealthCheck\CheckDiskSpaceAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\CheckHealthEndpointAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\CheckMemoryUsageAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\RunSmokeTestsAction;
use Shaf\LaravelDeployer\Deployer;

/**
 * Service for managing deployment health checks
 *
 * Consolidates pre-deployment and post-deployment health check logic
 * into a single service with configurable options.
 */
class HealthCheckService
{
    public function __construct(protected Deployer $deployer) {}

    /**
     * Run pre-deployment health checks
     *
     * Checks server resources (disk space, memory) before deployment
     * to ensure the server can handle the deployment.
     */
    public function runPreDeployment(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->deployer->writeln('🔍 Checking server resources...');
        CheckDiskSpaceAction::run($this->deployer);
        CheckMemoryUsageAction::run($this->deployer);
        $this->deployer->writeln('');
    }

    /**
     * Run post-deployment health checks
     *
     * Verifies the deployed application is working correctly by checking
     * health endpoints and running smoke tests.
     *
     * @param  string|null  $appUrl  Optional application URL to check
     */
    public function runPostDeployment(?string $appUrl = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $appUrl = $appUrl ?? $this->detectApplicationUrl();

        $this->deployer->writeln('🔍 Running deployment health checks...');
        $this->deployer->writeln('');

        CheckHealthEndpointAction::run($this->deployer, null, $appUrl);
        RunSmokeTestsAction::run($this->deployer, $appUrl);

        $this->deployer->writeln('');
        $this->deployer->writeln('✅ All health checks passed!');
    }

    /**
     * Detect the application URL from the deployed application
     *
     * Reads the APP_URL from the .env file instead of using tinker
     * for better performance.
     */
    public function detectApplicationUrl(): string
    {
        $currentPath = $this->deployer->getCurrentPath();

        // Try to read APP_URL from .env file (faster than tinker)
        $this->deployer->writeln("run grep '^APP_URL=' {$currentPath}/.env | cut -d '=' -f2 | tr -d '\"' | tr -d \"'\"");
        $appUrl = $this->deployer->run("grep '^APP_URL=' {$currentPath}/.env | cut -d '=' -f2 | tr -d '\"' | tr -d \"'\"");

        // Fallback to tinker if .env read fails
        if (empty(trim($appUrl))) {
            $this->deployer->writeln("run cd {$currentPath} && php artisan tinker --execute=\"echo config('app.url');\"");
            $appUrl = $this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo config('app.url');\"");
        }

        $appUrl = trim($appUrl);
        $this->deployer->writeln($appUrl);

        return $appUrl;
    }

    /**
     * Check if health checks are enabled in configuration
     */
    protected function isEnabled(): bool
    {
        return config('laravel-deployer.health_check.enabled', true);
    }
}
