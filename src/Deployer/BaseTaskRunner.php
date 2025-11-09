<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Constants\Paths;
use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;

abstract class BaseTaskRunner
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config,
        protected string $releaseName = '',
    ) {}

    protected function task(string $name, callable $callback): void
    {
        $this->output->task($name);
        $callback($this);
    }

    protected function run(string $command): string
    {
        return $this->executor->execute($command);
    }

    protected function test(string $condition): bool
    {
        return $this->executor->test($condition);
    }

    protected function getDeployPath(): string
    {
        return $this->config->deployPath;
    }

    protected function getReleasePath(): string
    {
        return $this->config->deployPath . '/' . Paths::RELEASES_DIR . '/' . $this->releaseName;
    }

    protected function getCurrentPath(): string
    {
        return $this->config->deployPath . '/' . Paths::CURRENT_SYMLINK;
    }

    protected function getSharedPath(): string
    {
        return $this->config->deployPath . '/' . Paths::SHARED_DIR;
    }

    protected function getDepPath(): string
    {
        return $this->config->deployPath . '/' . Paths::DEP_DIR;
    }

    protected function getLockFile(): string
    {
        return $this->config->deployPath . '/' . Paths::LOCK_FILE;
    }

    protected function getReleaseName(): string
    {
        return $this->releaseName;
    }

    protected function setReleaseName(string $releaseName): void
    {
        $this->releaseName = $releaseName;
    }

    protected function isLocal(): bool
    {
        return $this->executor->isLocal();
    }

    protected function getEnvironment(): string
    {
        return $this->config->environment->value;
    }

    protected function getHostname(): string
    {
        return $this->config->hostname;
    }

    protected function getApplicationName(): string
    {
        return $this->config->application;
    }

    protected function getBranch(): string
    {
        return $this->config->branch;
    }
}
