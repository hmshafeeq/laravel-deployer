<?php

namespace Shaf\LaravelDeployer\Concerns;

use Shaf\LaravelDeployer\Services\ConfigService;

/**
 * Provides server selection functionality for commands.
 *
 * Requires the using class to be an Illuminate\Console\Command
 * with 'server' argument and '--select' option in its signature.
 */
trait SelectsServer
{
    /**
     * Get the server name from argument, interactive selection, or fail.
     */
    protected function getServerName(): ?string
    {
        $serverName = $this->argument('server');

        if ($serverName) {
            return $serverName;
        }

        if ($this->option('select')) {
            return $this->selectServerInteractively();
        }

        $this->error('Please provide a server name or use --select option');

        return null;
    }

    /**
     * Show available servers and let user select one.
     */
    protected function selectServerInteractively(): ?string
    {
        $configService = new ConfigService(base_path());
        $servers = $configService->getAvailableEnvironments();

        if (empty($servers)) {
            $this->error('No servers configured in deploy.json');

            return null;
        }

        return $this->choice('Select a server', $servers);
    }
}
