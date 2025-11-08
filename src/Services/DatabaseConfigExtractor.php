<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Deployer\Deployer;
use Shaf\LaravelDeployer\ValueObjects\DatabaseConfig;

class DatabaseConfigExtractor
{
    public function __construct(
        private Deployer $deployer
    ) {}

    /**
     * Extract database configuration from remote server
     */
    public function extract(string $currentPath): DatabaseConfig
    {
        $this->deployer->writeln("🔍 Getting database configuration...");

        $connection = $this->getConfigValue($currentPath, 'database.default');

        $config = [
            'host' => $this->getConfigValue($currentPath, "database.connections.{$connection}.host"),
            'database' => $this->getConfigValue($currentPath, "database.connections.{$connection}.database"),
            'username' => $this->getConfigValue($currentPath, "database.connections.{$connection}.username"),
            'password' => $this->getConfigValue($currentPath, "database.connections.{$connection}.password"),
        ];

        $this->validate($config);

        return new DatabaseConfig(
            host: $config['host'],
            database: $config['database'],
            username: $config['username'],
            password: $config['password']
        );
    }

    /**
     * Get a configuration value from the remote server
     */
    private function getConfigValue(string $path, string $key): string
    {
        $command = "cd {$path} && php artisan tinker --execute=\"echo config('{$key}');\"";
        $this->deployer->writeln("run {$command}");

        $value = trim($this->deployer->run($command));
        $this->deployer->writeln($value);

        return $value;
    }

    /**
     * Validate database configuration
     */
    private function validate(array $config): void
    {
        if (empty($config['host']) || !preg_match('/^[a-zA-Z0-9.-]+$/', $config['host'])) {
            throw new \RuntimeException("Invalid database host: {$config['host']}");
        }

        if (empty($config['database']) || !preg_match('/^[a-zA-Z0-9_]+$/', $config['database'])) {
            throw new \RuntimeException("Invalid database name: {$config['database']}");
        }

        if (empty($config['username']) || !preg_match('/^[a-zA-Z0-9_@.-]+$/', $config['username'])) {
            throw new \RuntimeException("Invalid database user: {$config['username']}");
        }

        if (empty($config['password'])) {
            throw new \RuntimeException('Database password cannot be empty');
        }
    }
}
