<?php

namespace Shaf\LaravelDeployer\Commands\Traits;

use Shaf\LaravelDeployer\Services\ServerManager;

/**
 * Trait for managing environment selection in commands
 *
 * This trait provides UI layer functionality for selecting environments
 * from available .deploy environment configurations. It delegates
 * business logic to the ServerManager service.
 */
trait ManagesEnvironmentSelection
{
    protected ?ServerManager $serverManager = null;

    /**
     * Get server manager instance
     */
    protected function getServerManager(): ServerManager
    {
        return $this->serverManager ??= new ServerManager;
    }

    /**
     * Get environment name from argument/option with validation
     *
     * This method handles the complete environment selection flow:
     * 1. Check for --select option (interactive selection)
     * 2. Check for environment argument (validate it)
     * 3. Check available environments (auto-select if only one)
     * 4. Fall back to interactive selection
     *
     * @return string|null Environment name or null if none selected/available
     */
    protected function getEnvironmentName(): ?string
    {
        if ($this->option('select')) {
            return $this->selectEnvironmentInteractively();
        }

        $environment = $this->argument('environment');
        if ($environment) {
            if (! $this->validateEnvironmentWithFeedback($environment)) {
                return null;
            }

            return $environment;
        }

        // If no environment provided, check available environments
        $environments = $this->getServerManager()->getAvailableServers();
        if (empty($environments)) {
            $this->displayNoEnvironmentsError();

            return null;
        }

        if (count($environments) === 1) {
            return $environments[0];
        }

        return $this->selectEnvironmentInteractively();
    }

    /**
     * Interactive environment selection (UI layer)
     *
     * Displays a numbered list of available environments and prompts
     * the user to select one.
     *
     * @return string|null Selected environment name or null if invalid selection
     */
    protected function selectEnvironmentInteractively(): ?string
    {
        $environments = $this->getServerManager()->getAvailableServers();

        if (empty($environments)) {
            $this->displayNoEnvironmentsError();

            return null;
        }

        $this->info('📋 Available environments:');
        foreach ($environments as $index => $environment) {
            $this->line('   '.($index + 1).". {$environment}");
        }
        $this->line('');

        $choice = $this->ask('Select environment', '1');
        $index = (int) $choice - 1;

        if (! isset($environments[$index])) {
            $this->error('❌ Invalid environment selection');

            return null;
        }

        return $environments[$index];
    }

    /**
     * Validate environment and provide user feedback
     *
     * Checks if the environment exists and displays appropriate error
     * messages if not found.
     *
     * @param  string  $environment  Environment name to validate
     * @return bool True if environment exists, false otherwise
     */
    protected function validateEnvironmentWithFeedback(string $environment): bool
    {
        $serverManager = $this->getServerManager();

        if (! $serverManager->serverExists($environment)) {
            $this->error("❌ Environment '{$environment}' not found");

            $availableEnvironments = $serverManager->getAvailableServers();
            if (! empty($availableEnvironments)) {
                $this->info('💡 Available environments: '.implode(', ', $availableEnvironments));
            }

            return false;
        }

        return true;
    }

    /**
     * Display "no environments found" error message
     *
     * Shows contextual error message based on whether the .deploy
     * directory exists or not.
     */
    protected function displayNoEnvironmentsError(): void
    {
        if (! $this->getServerManager()->deployDirectoryExists()) {
            $this->error('❌ .deploy directory not found.');
        } else {
            $this->error('❌ No environment files found in .deploy/');
        }

        $this->info('💡 Run: php artisan laravel-deployer:install');
    }
}
