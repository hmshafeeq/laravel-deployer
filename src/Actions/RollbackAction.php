<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Concerns\ManagesLocking;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Exceptions\DeploymentException;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\DeploymentService;

/**
 * Rollback to previous release action.
 * Handles complete rollback workflow in a single operation.
 */
class RollbackAction
{
    use ManagesLocking;
    public function __construct(
        private DeploymentService $deployment,
        private CommandService $cmd,
        private DeploymentConfig $config
    ) {
        $this->deployment->setCommandService($cmd);
    }

    /**
     * Execute rollback to previous release
     */
    public function execute(): void
    {
        $this->cmd->info("🔄 Starting rollback for {$this->config->environment->value}");
        $this->cmd->newLine();

        // 1. Check and lock deployment
        $this->lockDeployment();

        try {
            // 2. Get current and previous releases
            $current = $this->deployment->getCurrentRelease();
            $previous = $this->deployment->getPreviousRelease();

            if (! $previous) {
                throw DeploymentException::releaseNotFound('No previous release available for rollback');
            }

            $this->cmd->info("Current release: {$current}");
            $this->cmd->info("Rolling back to: {$previous}");
            $this->cmd->newLine();

            // 3. Verify previous release exists
            $previousPath = $this->deployment->getReleasePath($previous);
            if (! $this->cmd->directoryExists($previousPath)) {
                throw DeploymentException::releaseNotFound("Previous release directory not found: {$previousPath}");
            }

            // 4. Symlink to previous release
            $this->symlinkPreviousRelease($previous, $previousPath);

            // 5. Update latest release file
            $this->deployment->writeLatestRelease($previous);

            // 6. Log rollback
            $this->logRollback($current, $previous);

            $this->cmd->newLine();
            $this->cmd->success('✅ Rollback completed successfully!');
            $this->cmd->success("🔙 Now running release: {$previous}");

        } finally {
            // Always unlock deployment
            $this->unlockDeployment();
        }
    }


    /**
     * Symlink current to previous release
     */
    private function symlinkPreviousRelease(string $previous, string $previousPath): void
    {
        $this->cmd->task('release:symlink');

        $currentPath = $this->deployment->getCurrentPath();

        $this->cmd->remote("ln -nfs {$previousPath} {$currentPath}");

        $this->cmd->success("Symlinked to release: {$previous}");
    }

    /**
     * Log rollback operation
     */
    private function logRollback(string $from, string $to): void
    {
        $deployPath = $this->config->deployPath;
        $logFile = "{$deployPath}/.dep/deploy.log";

        $timestamp = date('Y-m-d H:i:s');
        $user = $this->deployment->getUser();
        $logEntry = "[{$timestamp}] {$user} rolled back from {$from} to {$to} on {$this->config->environment->value}";

        $this->cmd->remote("echo '{$logEntry}' >> {$logFile}");
    }

}
