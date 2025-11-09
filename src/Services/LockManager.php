<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Constants\Paths;
use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Exceptions\DeploymentException;

class LockManager
{
    private string $lockFile;

    public function __construct(
        private CommandExecutor $executor,
        private OutputService $output,
        private string $deployPath,
        private string $user = 'deployer',
    ) {
        $this->lockFile = "{$deployPath}/" . Paths::LOCK_FILE;
    }

    public function check(): void
    {
        $this->output->debug("Checking for deployment lock...");

        if ($this->isLocked()) {
            throw DeploymentException::locked($this->lockFile);
        }

        $this->output->debug("No deployment lock found");
    }

    public function lock(): void
    {
        $this->output->debug("Creating deployment lock...");

        $this->executor->execute("echo '{$this->user}' > {$this->lockFile}");

        $this->output->debug("Deployment locked by {$this->user}");
    }

    public function unlock(): void
    {
        $this->output->debug("Removing deployment lock...");

        $this->executor->execute("rm -f {$this->lockFile}");

        $this->output->debug("Deployment unlocked");
    }

    public function isLocked(): bool
    {
        return $this->executor->test("[ -f {$this->lockFile} ]");
    }

    public function getLockedBy(): ?string
    {
        if (!$this->isLocked()) {
            return null;
        }

        $content = $this->executor->execute("cat {$this->lockFile} 2>/dev/null || echo ''");

        return trim($content) ?: null;
    }
}
