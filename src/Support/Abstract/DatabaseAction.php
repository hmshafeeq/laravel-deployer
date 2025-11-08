<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer\Deployer;
use Shaf\LaravelDeployer\Services\DatabaseConfigExtractor;

abstract class DatabaseAction extends Action
{
    public function __construct(
        protected Deployer $deployer,
        protected ?DatabaseConfigExtractor $configExtractor = null
    ) {
        $this->configExtractor = $configExtractor ?? new DatabaseConfigExtractor($deployer);
    }

    /**
     * Get the deployment path
     */
    protected function getDeployPath(): string
    {
        return $this->deployer->getDeployPath();
    }

    /**
     * Get the configured backup path
     */
    protected function getBackupPath(): string
    {
        return config('laravel-deployer.backup.path', 'shared/backups');
    }

    /**
     * Get the full backup path
     */
    protected function getFullBackupPath(): string
    {
        return $this->getDeployPath() . '/' . $this->getBackupPath();
    }

    /**
     * Write a line to output
     */
    protected function writeln(string $message, string $style = 'info'): void
    {
        $this->deployer->writeln($message, $style);
    }

    /**
     * Run a command on the remote server
     */
    protected function run(string $command): string
    {
        return $this->deployer->run($command);
    }
}
