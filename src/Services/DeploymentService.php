<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Constants\Paths;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\ReleaseInfo;
use Shaf\LaravelDeployer\Exceptions\DeploymentException;
use Symfony\Component\Process\Process;

/**
 * Unified service for deployment management.
 * Merges: ReleaseManager, LockManager, DeploymentOperationsService, DeploymentServiceFactory
 */
class DeploymentService
{
    private CommandService $cmd;
    private string $lockFile;
    private string $currentReleaseName = '';

    public function __construct(
        private DeploymentConfig $config,
        private string $basePath
    ) {
        $this->lockFile = "{$config->deployPath}/" . Paths::LOCK_FILE;
    }

    /**
     * Set the command service (injected after construction)
     */
    public function setCommandService(CommandService $cmd): void
    {
        $this->cmd = $cmd;
    }

    // ============================================================
    // Release Management Methods
    // ============================================================

    /**
     * Generate a unique release name (format: YYYYMM.N)
     */
    public function generateReleaseName(): string
    {
        $yearMonth = date('Ym');
        $counterDir = "{$this->config->deployPath}/" . Paths::COUNTER_DIR;
        $counterFile = "{$counterDir}/{$yearMonth}.txt";

        // Ensure the folder exists
        $this->cmd->remote("mkdir -p {$counterDir}");

        // Read counter or start from 0
        $count = $this->cmd->remote("if [ -f {$counterFile} ]; then cat {$counterFile}; else echo 0; fi");
        $count = (int) $count + 1;

        // Save updated counter
        $this->cmd->remote("echo {$count} > {$counterFile}");

        $releaseName = "{$yearMonth}.{$count}";
        $this->currentReleaseName = $releaseName;

        $this->cmd->debug("Generated release name: {$releaseName}");

        return $releaseName;
    }

    /**
     * Get list of all releases (sorted newest first)
     */
    public function getReleases(): array
    {
        $releasesPath = "{$this->config->deployPath}/" . Paths::RELEASES_DIR;

        if (!$this->cmd->directoryExists($releasesPath)) {
            return [];
        }

        $output = $this->cmd->remote("cd {$releasesPath} && ls -t -1 2>/dev/null || echo ''");

        if (empty(trim($output))) {
            return [];
        }

        $releases = array_filter(explode("\n", trim($output)));

        return array_values($releases);
    }

    /**
     * Get the currently active release name
     */
    public function getCurrentRelease(): ?string
    {
        $currentPath = "{$this->config->deployPath}/" . Paths::CURRENT_SYMLINK;

        if (!$this->cmd->symlinkExists($currentPath)) {
            return null;
        }

        $output = $this->cmd->remote("basename \$(readlink -f {$currentPath}) 2>/dev/null || echo ''");

        if (empty(trim($output))) {
            return null;
        }

        return trim($output);
    }

    /**
     * Get the previous release (for rollback)
     */
    public function getPreviousRelease(): ?string
    {
        $current = $this->getCurrentRelease();
        $releases = $this->getReleases();

        if (empty($releases) || count($releases) < 2) {
            return null;
        }

        foreach ($releases as $release) {
            if ($release !== $current) {
                return $release;
            }
        }

        return null;
    }

    /**
     * Log release information
     */
    public function logRelease(ReleaseInfo $release): void
    {
        $logFile = "{$this->config->deployPath}/" . Paths::RELEASES_LOG;
        $logEntry = json_encode($release->toLogEntry());

        $this->cmd->remote("echo '{$logEntry}' >> {$logFile}");
    }

    /**
     * Write latest release to file
     */
    public function writeLatestRelease(string $releaseName): void
    {
        $latestReleaseFile = "{$this->config->deployPath}/" . Paths::LATEST_RELEASE;
        $this->cmd->remote("echo {$releaseName} > {$latestReleaseFile}");
    }

    /**
     * Get current user for deployment
     */
    public function getUser(): string
    {
        if ($this->config->isLocal) {
            $process = Process::fromShellCommandline('git config --get user.name');
            $process->run();
            return trim($process->getOutput()) ?: 'unknown';
        }

        return 'deployer';
    }

    // ============================================================
    // Lock Management Methods
    // ============================================================

    /**
     * Check if deployment is locked (throws exception if locked)
     */
    public function check(): void
    {
        $this->cmd->debug("Checking for deployment lock...");

        if ($this->isLocked()) {
            throw DeploymentException::locked($this->lockFile);
        }

        $this->cmd->debug("No deployment lock found");
    }

    /**
     * Lock deployment
     */
    public function lock(): void
    {
        $this->cmd->debug("Creating deployment lock...");

        $user = $this->getUser();
        $this->cmd->remote("echo '{$user}' > {$this->lockFile}");

        $this->cmd->debug("Deployment locked by {$user}");
    }

    /**
     * Unlock deployment
     */
    public function unlock(): void
    {
        $this->cmd->debug("Removing deployment lock...");

        $this->cmd->remote("rm -f {$this->lockFile}");

        $this->cmd->debug("Deployment unlocked");
    }

    /**
     * Check if deployment is currently locked
     */
    public function isLocked(): bool
    {
        return $this->cmd->fileExists($this->lockFile);
    }

    /**
     * Get who locked the deployment
     */
    public function getLockedBy(): ?string
    {
        if (!$this->isLocked()) {
            return null;
        }

        $content = $this->cmd->remote("cat {$this->lockFile} 2>/dev/null || echo ''");

        return trim($content) ?: null;
    }

    // ============================================================
    // Path Helper Methods
    // ============================================================

    public function getDeployPath(): string
    {
        return $this->config->deployPath;
    }

    public function getReleasePath(string $releaseName): string
    {
        return "{$this->config->deployPath}/" . Paths::RELEASES_DIR . "/{$releaseName}";
    }

    public function getSharedPath(): string
    {
        return "{$this->config->deployPath}/" . Paths::SHARED_DIR;
    }

    public function getCurrentPath(): string
    {
        return "{$this->config->deployPath}/" . Paths::CURRENT_SYMLINK;
    }

    public function getCurrentReleaseName(): string
    {
        return $this->currentReleaseName;
    }

    public function setCurrentReleaseName(string $releaseName): void
    {
        $this->currentReleaseName = $releaseName;
    }
}
