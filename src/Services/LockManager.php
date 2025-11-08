<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Deployer\Deployer;

class LockManager
{
    public function __construct(
        protected Deployer $deployer
    ) {}

    /**
     * Check if deployment is locked
     *
     * @throws \RuntimeException if deployment is locked
     */
    public function checkLock(): void
    {
        $lockFile = $this->getLockFilePath();

        $this->deployer->writeln("run if [ -f {$lockFile} ]; then echo +legitimate; fi");
        $exists = $this->deployer->run("if [ -f {$lockFile} ]; then echo +legitimate; fi");

        if (!empty($exists)) {
            $this->deployer->writeln($exists);
            throw new \RuntimeException("Deployment is locked");
        }
    }

    /**
     * Check if deployment is currently locked (non-throwing)
     */
    public function isLocked(): bool
    {
        $lockFile = $this->getLockFilePath();
        $exists = $this->deployer->run("if [ -f {$lockFile} ]; then echo +legitimate; fi");

        return !empty($exists);
    }

    /**
     * Lock the deployment
     */
    public function lock(): void
    {
        $user = $this->deployer->runLocally('git config --get user.name');
        $this->deployer->writeln("run git config --get user.name");
        $this->deployer->writeln($user);

        $lockFile = $this->getLockFilePath();
        $this->deployer->writeln("run [ -f {$lockFile} ] && echo +locked || echo '{$user}' > {$lockFile}");
        $result = $this->deployer->run("[ -f {$lockFile} ] && echo +locked || echo '{$user}' > {$lockFile}");
        if (!empty($result)) {
            $this->deployer->writeln($result);
        }
    }

    /**
     * Unlock the deployment
     */
    public function unlock(): void
    {
        $lockFile = $this->getLockFilePath();
        $this->deployer->writeln("run rm -f {$lockFile}");
        $this->deployer->run("rm -f {$lockFile}");
    }

    /**
     * Get the lock file path
     */
    protected function getLockFilePath(): string
    {
        return $this->deployer->getDeployPath() . '/.dep/deploy.lock';
    }
}
