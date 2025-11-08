<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Deployer;
use Symfony\Component\Yaml\Yaml;

/**
 * Base class for deployer commands
 *
 * Provides common functionality for all deployer commands including
 * configuration loading, deployer initialization, and error handling.
 */
abstract class BaseDeployerCommand extends Command
{
    /**
     * Initialize deployer with configuration loading
     *
     * This is a convenience method that combines configuration loading
     * and deployer initialization into a single call.
     *
     * @param string $environment Environment name (e.g., staging, production)
     * @return Deployer Initialized deployer instance
     * @throws \RuntimeException If configuration loading fails
     */
    protected function initDeployer(string $environment): Deployer
    {
        $config = $this->loadConfiguration($environment);

        return Deployer::init($environment, $config);
    }

    /**
     * Load configuration from deploy.yaml
     *
     * Loads and parses the deploy.yaml configuration file and returns
     * the configuration for the specified environment.
     *
     * @param string $environment Environment name
     * @return array Configuration array for the environment
     * @throws \RuntimeException If configuration file not found or environment not configured
     */
    protected function loadConfiguration(string $environment): array
    {
        $yamlPath = $this->findDeployYamlPath();

        if (!file_exists($yamlPath)) {
            throw new \RuntimeException('deploy.yaml not found. Run: php artisan laravel-deployer:install');
        }

        $yaml = Yaml::parseFile($yamlPath);

        // Support both config structures for backwards compatibility
        // Structure 1: $yaml['hosts'][$environment]
        if (isset($yaml['hosts'][$environment])) {
            $hostConfig = $yaml['hosts'][$environment];

            return [
                'environment' => $environment,
                'hostname' => $hostConfig['hostname'] ?? 'localhost',
                'remote_user' => $hostConfig['remote_user'] ?? 'deploy',
                'deploy_path' => $hostConfig['deploy_path'] ?? '/var/www/app',
                'branch' => $hostConfig['branch'] ?? 'main',
                'local' => $hostConfig['local'] ?? false,
                'application' => $yaml['config']['application'] ?? 'Application',
            ];
        }

        // Structure 2: $yaml[$environment] (legacy)
        if (isset($yaml[$environment])) {
            return $yaml[$environment];
        }

        throw new \RuntimeException("Environment '{$environment}' not found in deploy.yaml");
    }

    /**
     * Find deploy.yaml file path
     *
     * Checks multiple locations for the deploy.yaml file:
     * 1. .deploy/deploy.yaml (preferred)
     * 2. deploy.yaml (fallback)
     *
     * @return string Path to deploy.yaml file
     */
    protected function findDeployYamlPath(): string
    {
        $deployYamlPath = base_path('.deploy/deploy.yaml');

        if (file_exists($deployYamlPath)) {
            return $deployYamlPath;
        }

        return base_path('deploy.yaml');
    }

    /**
     * Execute operation with standardized error handling
     *
     * Wraps an operation in try-catch and provides consistent
     * success/error messaging.
     *
     * @param callable $operation Operation to execute
     * @param string $successMessage Message to display on success
     * @param string $errorMessage Message to display on error
     * @return int Command exit code (SUCCESS or FAILURE)
     */
    protected function executeWithErrorHandling(
        callable $operation,
        string $successMessage,
        string $errorMessage
    ): int {
        try {
            $operation();

            $this->line('');
            $this->info($successMessage);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->line('');
            $this->error($errorMessage);
            $this->error($e->getMessage());

            if ($this->getOutput()->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Confirm operation for non-local environments
     *
     * Shows a confirmation prompt for operations on non-local environments
     * unless --no-confirm flag is provided.
     *
     * @param string $environment Environment name
     * @param string $operation Operation description
     * @param bool $noConfirm Skip confirmation if true
     * @return bool True if operation should proceed, false otherwise
     */
    protected function confirmNonLocalOperation(
        string $environment,
        string $operation,
        bool $noConfirm = false
    ): bool {
        if ($environment === 'local' || $noConfirm) {
            return true;
        }

        $this->warn("⚠️  You are about to {$operation} on {$environment}");

        if (!$this->confirm('Do you want to continue?', false)) {
            $this->info('Operation cancelled.');

            return false;
        }

        return true;
    }
}
