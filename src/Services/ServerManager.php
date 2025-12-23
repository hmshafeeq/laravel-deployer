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

        try {
            $envFiles = File::glob($deployDir.'/.env.*');
            $servers = [];

            foreach ($envFiles as $file) {
                $filename = basename($file);
                if (preg_match('/^\.env\.(.+?)(?:\.example)?$/', $filename, $matches)) {
                    if (! str_ends_with($filename, '.example')) {
                        $servers[] = $matches[1];
                    }
                }
            }

            return $servers;
        } catch (\Exception $e) {
            return [];
        }
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
