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

    private bool $confirmed = false;

    public function __construct(
        private DeploymentService $deployment,
        private CommandService $cmd,
        private DeploymentConfig $config
    ) {}

    /**
     * Execute rollback to previous release
     */
    public function execute(bool $skipConfirmation = false): void
    {
        $this->cmd->info("🔄 Starting rollback for {$this->config->environment->value}");
        $this->cmd->newLine();

        // 1. Check and lock deployment
        $this->lockDeployment();

        try {
            // 2. Get current and previous releases with timestamps
            $current = $this->deployment->getCurrentRelease();
            $previous = $this->deployment->getPreviousRelease();

            if (! $previous) {
                throw DeploymentException::releaseNotFound('No previous release available for rollback');
            }

            // 3. Get release timestamps for better UX
            $currentTimestamp = $this->getReleaseTimestamp($current);
            $previousTimestamp = $this->getReleaseTimestamp($previous);

            // 4. Show enhanced rollback information
            $this->showRollbackInfo($current, $previous, $currentTimestamp, $previousTimestamp);

            // 5. Verify previous release exists
            $previousPath = $this->deployment->getReleasePath($previous);
            if (! $this->cmd->directoryExists($previousPath)) {
                throw DeploymentException::releaseNotFound("Previous release directory not found: {$previousPath}");
            }

            // 6. Confirm rollback (unless skipped)
            if (! $skipConfirmation && ! $this->confirmRollback($current, $previous)) {
                $this->cmd->warning('Rollback cancelled by user');

                return;
            }

            // 7. Symlink to previous release
            $this->symlinkPreviousRelease($previous, $previousPath);

            // 8. Update latest release file
            $this->deployment->writeLatestRelease($previous);

            // 9. Log rollback
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
     * Show enhanced rollback information with timestamps
     */
    private function showRollbackInfo(
        string $current,
        string $previous,
        ?string $currentTimestamp,
        ?string $previousTimestamp
    ): void {
        $this->cmd->newLine();
        $this->cmd->info('Rolling back from:');
        $this->cmd->line("  Current:  {$current}");
        if ($currentTimestamp) {
            $this->cmd->line("            ({$currentTimestamp})");
        }

        $this->cmd->newLine();
        $this->cmd->info('Rolling back to:');
        $this->cmd->line("  Target:   {$previous}");
        if ($previousTimestamp) {
            $this->cmd->line("            ({$previousTimestamp})");
        }
        $this->cmd->newLine();
    }

    /**
     * Confirm rollback with user
     */
    private function confirmRollback(string $from, string $to): bool
    {
        return $this->cmd->confirm(
            "⚠️  Are you sure you want to rollback from {$from} to {$to}?",
            false
        );
    }

    /**
     * Get human-readable timestamp for a release
     */
    private function getReleaseTimestamp(string $release): ?string
    {
        // Parse release name format: YYYYMM.DD.HH or similar
        // Example: 202512.24 -> December 24, 2025
        if (preg_match('/^(\d{4})(\d{2})\.(\d{2})/', $release, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            $day = $matches[3];

            try {
                $date = new \DateTime("{$year}-{$month}-{$day}");
                $now = new \DateTime;
                $diff = $now->diff($date);

                if ($diff->days === 0) {
                    return 'deployed today';
                } elseif ($diff->days === 1) {
                    return 'deployed yesterday';
                } elseif ($diff->days < 7) {
                    return "deployed {$diff->days} days ago";
                } else {
                    return 'deployed on '.$date->format('M j, Y');
                }
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Symlink current to previous release
     */
    private function symlinkPreviousRelease(string $previous, string $previousPath): void
    {
        $this->cmd->task('release:symlink');

        $currentPath = $this->deployment->getCurrentPath();
        $escapedPreviousPath = CommandService::escapePath($previousPath);
        $escapedCurrentPath = CommandService::escapePath($currentPath);

        $this->cmd->remote("ln -nfs {$escapedPreviousPath} {$escapedCurrentPath}");

        $this->cmd->success("Symlinked to release: {$previous}");
    }

    /**
     * Log rollback operation
     */
    private function logRollback(string $from, string $to): void
    {
        $logFile = "{$this->config->deployPath}/.dep/deploy.log";
        $escapedLogFile = CommandService::escapePath($logFile);

        $timestamp = date('Y-m-d H:i:s');
        $user = $this->deployment->getUser();
        $logEntry = "[{$timestamp}] {$user} rolled back from {$from} to {$to} on {$this->config->environment->value}";
        $escapedLogEntry = escapeshellarg($logEntry);

        $this->cmd->remote("echo {$escapedLogEntry} >> {$escapedLogFile}");
    }
}
