<?php

namespace Shaf\LaravelDeployer\Actions\Maintenance;

use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Support\Abstract\Action;

/**
 * Restart Laravel queue workers
 *
 * This action restarts all Laravel queue workers on the remote server
 * using the artisan queue:restart command.
 */
class RestartQueueWorkersAction extends Action
{
    /**
     * Create a new RestartQueueWorkersAction instance
     */
    public function __construct(
        protected Deployer $deployer
    ) {}

    /**
     * Execute the queue restart operation
     */
    public function execute(): void
    {
        $this->writeln('🔄 Restarting queue workers...', 'info');

        try {
            $currentPath = $this->getCurrentPath();
            $this->cmd("cd {$currentPath} && php artisan queue:restart");
            $this->writeln('  ✓ Queue workers restarted', 'info');
        } catch (\Exception $e) {
            $this->writeln('  ⚠ Queue restart failed', 'comment');
        }
    }
}
