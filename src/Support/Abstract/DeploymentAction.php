<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer;

/**
 * Base class for deployment-related actions
 *
 * Provides common functionality for deployment operations including
 * release management, lock handling, and deployment-specific utilities.
 */
abstract class DeploymentAction extends Action
{
    public function __construct(
        protected Deployer $deployer
    ) {}

    /**
     * Check if this action requires deployment lock
     *
     * Override to specify if the action requires an active deployment lock.
     * Most deployment actions should require a lock to prevent concurrent deployments.
     *
     * @return bool
     */
    protected function requiresLock(): bool
    {
        return true;
    }

    /**
     * Check if this action supports rollback
     *
     * Override to specify if the action can be rolled back.
     * Actions that modify release state should support rollback.
     *
     * @return bool
     */
    protected function supportsRollback(): bool
    {
        return true;
    }

    /**
     * Get the releases path
     *
     * @return string
     */
    protected function getReleasesPath(): string
    {
        return $this->getDeployPath() . '/releases';
    }

    /**
     * Run a command in the current release directory
     *
     * @param string $command Command to run
     * @return string Command output
     */
    protected function runInRelease(string $command): string
    {
        $releasePath = $this->getReleasePath();
        return $this->cmd("cd {$releasePath} && {$command}");
    }

    /**
     * Run a command in the current (active) deployment directory
     *
     * @param string $command Command to run
     * @return string Command output
     */
    protected function runInCurrent(string $command): string
    {
        $currentPath = $this->getCurrentPath();
        return $this->cmd("cd {$currentPath} && {$command}");
    }

    /**
     * Check if a path exists on the remote server
     *
     * @param string $path Path to check
     * @return bool
     */
    protected function pathExists(string $path): bool
    {
        $result = $this->cmd("[ -e {$path} ] && echo 'exists' || echo 'not_exists'");
        return trim($result) === 'exists';
    }

    /**
     * Create a directory if it doesn't exist
     *
     * @param string $path Directory path
     * @param int $permissions Directory permissions (octal)
     * @return void
     */
    protected function ensureDirectoryExists(string $path, int $permissions = 0755): void
    {
        $permStr = decoct($permissions);
        $this->cmd("[ -d {$path} ] || mkdir -p {$path} && chmod {$permStr} {$path}");
    }
}
