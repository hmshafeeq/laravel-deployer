<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Actions\Service\ReloadSupervisorAction;
use Shaf\LaravelDeployer\Actions\Service\RestartNginxAction;
use Shaf\LaravelDeployer\Actions\Service\RestartPhpFpmAction;
use Shaf\LaravelDeployer\Deployer;

/**
 * Service for managing server service restarts
 *
 * Consolidates service restart logic into a single, reusable service
 * that can be configured via config file or method parameters.
 */
class ServiceRestarter
{
    protected array $enabledServices;

    public function __construct(
        protected Deployer $deployer,
        ?array $services = null
    ) {
        // Use provided services or fall back to config
        $this->enabledServices = $services ?? $this->getServicesFromConfig();
    }

    /**
     * Restart all enabled services
     *
     * @param bool $failSilently If true, service restart failures won't throw exceptions
     * @return void
     */
    public function restartAll(bool $failSilently = false): void
    {
        if (empty($this->enabledServices)) {
            return;
        }

        $this->deployer->writeln("🔄 Restarting services...");

        foreach ($this->enabledServices as $service => $enabled) {
            if (!$enabled) {
                continue;
            }

            try {
                $this->restartService($service);
            } catch (\Exception $e) {
                if ($failSilently) {
                    $this->deployer->writeln("  ⚠ {$service} restart failed: {$e->getMessage()}", 'comment');
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * Restart a specific service
     *
     * @param string $service Service name (php-fpm, nginx, supervisor)
     * @return void
     * @throws \RuntimeException If service name is not recognized
     */
    public function restartService(string $service): void
    {
        match ($service) {
            'php-fpm' => RestartPhpFpmAction::run($this->deployer),
            'nginx' => RestartNginxAction::run($this->deployer),
            'supervisor' => ReloadSupervisorAction::run($this->deployer),
            default => throw new \RuntimeException("Unknown service: {$service}")
        };
    }

    /**
     * Restart only specific services
     *
     * @param array $services List of service names to restart
     * @param bool $failSilently If true, failures won't throw exceptions
     * @return void
     */
    public function restartOnly(array $services, bool $failSilently = false): void
    {
        foreach ($services as $service) {
            try {
                $this->restartService($service);
            } catch (\Exception $e) {
                if ($failSilently) {
                    $this->deployer->writeln("  ⚠ {$service} restart failed: {$e->getMessage()}", 'comment');
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * Get services configuration from config file
     *
     * @return array
     */
    protected function getServicesFromConfig(): array
    {
        return config('laravel-deployer.services', [
            'php-fpm' => true,
            'nginx' => true,
            'supervisor' => true,
        ]);
    }
}
