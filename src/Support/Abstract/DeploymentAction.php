<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer\Deployer;

abstract class DeploymentAction extends Action
{
    public function __construct(
        protected Deployer $deployer
    ) {}

    /**
     * Get the deployment path
     */
    protected function getDeployPath(): string
    {
        return $this->deployer->getDeployPath();
    }

    /**
     * Get the current release path
     */
    protected function getReleasePath(): string
    {
        return $this->deployer->getReleasePath();
    }

    /**
     * Get the shared path
     */
    protected function getSharedPath(): string
    {
        return $this->deployer->getSharedPath();
    }

    /**
     * Get the current symlink path
     */
    protected function getCurrentPath(): string
    {
        return $this->deployer->getCurrentPath();
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
