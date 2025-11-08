<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class CheckLockAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $lockFile = $this->deployer->getDeployPath() . '/.dep/deploy.lock';

        $this->deployer->writeln("run if [ -f {$lockFile} ]; then echo +legitimate; fi");
        $exists = $this->deployer->run("if [ -f {$lockFile} ]; then echo +legitimate; fi");

        if (!empty($exists)) {
            $this->deployer->writeln($exists);
            throw new \RuntimeException("Deployment is locked");
        }
    }

    public function getName(): string
    {
        return 'deploy:check_lock';
    }
}
