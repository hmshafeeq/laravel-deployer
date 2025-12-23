<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Services\DatabaseConfigExtractor;

abstract class DatabaseAction extends Action
{
    protected ?DatabaseConfigExtractor $configExtractor;

    public function __construct(
        protected Deployer $deployer,
        ?DatabaseConfigExtractor $configExtractor = null
    ) {
        $this->configExtractor = $configExtractor ?? new DatabaseConfigExtractor($deployer);
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
        return $this->getDeployPath().'/'.$this->getBackupPath();
    }
}
