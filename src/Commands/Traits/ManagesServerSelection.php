<?php

namespace Shaf\LaravelDeployer\Commands\Traits;

use Shaf\LaravelDeployer\Services\ServerManager;

/**
 * Trait for managing server selection in commands
 *
 * This trait provides UI layer functionality for selecting servers
 * from available .deploy environment configurations. It delegates
 * business logic to the ServerManager service.
 */
trait ManagesServerSelection
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
     * Get server name from argument/option with validation
     *
     * This method handles the complete server selection flow:
     * 1. Check for --select option (interactive selection)
     * 2. Check for server argument (validate it)
     * 3. Check available servers (auto-select if only one)
     * 4. Fall back to interactive selection
     *
     * @return string|null Server name or null if none selected/available
     */
    protected function getServerName(): ?string
    {
        if ($this->option('select')) {
            return $this->selectServerInteractively();
        }

        $serverName = $this->argument('server');
        if ($serverName) {
            if (! $this->validateServerWithFeedback($serverName)) {
                return null;
            }

            return $serverName;
        }

        // If no server provided, check available servers
        $servers = $this->getServerManager()->getAvailableServers();
        if (empty($servers)) {
            $this->displayNoServersError();

            return null;
        }

        if (count($servers) === 1) {
            return $servers[0];
        }

        return $this->selectServerInteractively();
    }

    /**
     * Interactive server selection (UI layer)
     *
     * Displays a numbered list of available servers and prompts
     * the user to select one.
     *
     * @return string|null Selected server name or null if invalid selection
     */
    protected function selectServerInteractively(): ?string
    {
        $servers = $this->getServerManager()->getAvailableServers();

        if (empty($servers)) {
            $this->displayNoServersError();

            return null;
        }

        $this->info('📋 Available servers:');
        foreach ($servers as $index => $server) {
            $this->line('   '.($index + 1).". {$server}");
        }
        $this->line('');

        $choice = $this->ask('Select server', '1');
        $index = (int) $choice - 1;

        if (! isset($servers[$index])) {
            $this->error('❌ Invalid server selection');

            return null;
        }

        return $servers[$index];
    }

    /**
     * Validate server and provide user feedback
     *
     * Checks if the server exists and displays appropriate error
     * messages if not found.
     *
     * @param  string  $serverName  Server name to validate
     * @return bool True if server exists, false otherwise
     */
    protected function validateServerWithFeedback(string $serverName): bool
    {
        $serverManager = $this->getServerManager();

        if (! $serverManager->serverExists($serverName)) {
            $this->error("❌ Server '{$serverName}' not found");

            $availableServers = $serverManager->getAvailableServers();
            if (! empty($availableServers)) {
                $this->info('💡 Available servers: '.implode(', ', $availableServers));
            }

            return false;
        }

        return true;
    }

    /**
     * Display "no servers found" error message
     *
     * Shows contextual error message based on whether the .deploy
     * directory exists or not.
     */
    protected function displayNoServersError(): void
    {
        if (! $this->getServerManager()->deployDirectoryExists()) {
            $this->error('❌ .deploy directory not found.');
        } else {
            $this->error('❌ No environment files found in .deploy/');
        }

        $this->info('💡 Run: php artisan deployer:setup install');
    }
}
