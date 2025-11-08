<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer;

abstract class Action
{
    protected Deployer $deployer;

    /**
     * Execute the action
     *
     * @return mixed
     */
    abstract public function execute();

    /**
     * Static factory method for fluent execution
     *
     * @param mixed ...$args
     * @return mixed
     */
    public static function run(...$args)
    {
        $instance = new static(...$args);

        return $instance->execute();
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
}
