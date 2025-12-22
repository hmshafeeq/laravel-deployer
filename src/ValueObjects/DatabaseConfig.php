<?php

namespace Shaf\LaravelDeployer\ValueObjects;

class DatabaseConfig
{
    private ?string $configFile = null;

    public function __construct(
        public readonly string $host,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password
    ) {}

    /**
     * Create MySQL configuration file for secure authentication
     */
    public function createConfigFile(): string
    {
        if ($this->configFile !== null) {
            return $this->configFile;
        }

        $this->configFile = '/tmp/mysql_backup_'.uniqid().'.cnf';

        $content = "[client]\n";
        $content .= "host={$this->host}\n";
        $content .= "user={$this->username}\n";
        $content .= "password={$this->password}\n";

        file_put_contents($this->configFile, $content);

        return $this->configFile;
    }

    /**
     * Get the config file path (creates it if needed)
     */
    public function getConfigFile(): string
    {
        if ($this->configFile === null) {
            return $this->createConfigFile();
        }

        return $this->configFile;
    }

    /**
     * Clean up the configuration file
     */
    public function cleanupConfigFile(): void
    {
        if ($this->configFile !== null && file_exists($this->configFile)) {
            @unlink($this->configFile);
            $this->configFile = null;
        }
    }

    /**
     * Get configuration as array (for backward compatibility)
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'config_file' => $this->getConfigFile(),
        ];
    }
}
