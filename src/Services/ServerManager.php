<?php

namespace Shaf\LaravelDeployer\Services;

use Illuminate\Support\Facades\File;

class ServerManager
{
    /**
     * Get list of available servers from .deploy directory
     *
     * Scans the .deploy directory for .env.{server} files and returns
     * a list of server names (excluding .example files).
     *
     * @return array<string> List of available server names
     */
    public function getAvailableServers(): array
    {
        $deployDir = $this->getDeployDirectory();

        if (! File::exists($deployDir)) {
            return [];
        }

        return rescue(fn () => $this->extractServersFromEnvFiles($deployDir), []);
    }

    /**
     * Extract server names from .env.* files in deploy directory
     */
    private function extractServersFromEnvFiles(string $deployDir): array
    {
        return collect(File::glob($deployDir.'/.env.*'))
            ->map(fn ($file) => basename($file))
            ->filter(fn ($filename) => ! str_ends_with($filename, '.example'))
            ->map(fn ($filename) => preg_match('/^\.env\.(.+)$/', $filename, $m) ? $m[1] : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Check if a server exists in available servers
     *
     * @param  string  $serverName  The server name to check
     * @return bool True if server exists, false otherwise
     */
    public function serverExists(string $serverName): bool
    {
        return in_array($serverName, $this->getAvailableServers());
    }

    /**
     * Get the deploy directory path
     *
     * @return string Path to .deploy directory
     */
    public function getDeployDirectory(): string
    {
        return base_path('.deploy');
    }

    /**
     * Check if deploy directory exists
     *
     * @return bool True if directory exists, false otherwise
     */
    public function deployDirectoryExists(): bool
    {
        return File::exists($this->getDeployDirectory());
    }
}
