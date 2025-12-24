<?php

namespace Shaf\LaravelDeployer\Concerns;

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
        $this->deployment->check();
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
