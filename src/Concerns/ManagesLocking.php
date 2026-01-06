<?php

namespace Shaf\LaravelDeployer\Concerns;

use Shaf\LaravelDeployer\Exceptions\DeploymentException;

/**
 * Provides locking/unlocking functionality for deployment actions.
 *
 * Requires the using class to have:
 * - $this->cmd (CommandService)
 * - $this->deployment (DeploymentService)
 */
trait ManagesLocking
{
    /**
     * Lock deployment to prevent concurrent deployments
     */
    protected function lockDeployment(): void
    {
        $this->cmd->task('deployment:lock');

        if ($this->deployment->isLocked()) {
            $lockedBy = $this->deployment->getLockedBy() ?? 'unknown';
            $this->cmd->warning("Deployment is currently locked by: {$lockedBy}");

            if ($this->cmd->confirm('Do you want to force unlock and continue?', false)) {
                $this->cmd->info('Removing existing lock...');
                $this->deployment->unlock();
            } else {
                throw DeploymentException::locked($this->deployment->getLockFile());
            }
        }

        $this->deployment->lock();
        $this->cmd->success('Deployment locked');
    }

    /**
     * Unlock deployment
     */
    protected function unlockDeployment(): void
    {
        $this->deployment->unlock();
    }
}
