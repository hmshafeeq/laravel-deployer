<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Services\OutputService;

class LockDeploymentAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected string $lockFile
    ) {
    }

    public function check(): void
    {
        $this->output->info("Checking for deployment lock...");

        $lockExists = trim($this->executor->execute("test -f {$this->lockFile} && echo 'OK' || echo 'FAIL'"));

        if ($lockExists === 'OK') {
            throw new \RuntimeException(
                "Deployment is locked. Another deployment may be in progress.\n" .
                "If you're sure no deployment is running, remove the lock file:\n" .
                "rm {$this->lockFile}"
            );
        }

        $this->output->success("No deployment lock found");
    }

    public function lock(): void
    {
        $this->output->info("Creating deployment lock...");
        $this->executor->execute("echo $$ > {$this->lockFile}");
        $this->output->success("Deployment locked");
    }

    public function unlock(): void
    {
        $this->output->info("Removing deployment lock...");
        $this->executor->execute("rm -f {$this->lockFile}");
        $this->output->success("Deployment unlocked");
    }

    public function isLocked(): bool
    {
        $lockExists = trim($this->executor->execute("test -f {$this->lockFile} && echo 'OK' || echo 'FAIL'"));
        return $lockExists === 'OK';
    }
}
