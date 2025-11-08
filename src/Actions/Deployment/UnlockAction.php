<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class UnlockAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $lockFile = $this->deployer->getDeployPath() . '/.dep/deploy.lock';
        $this->deployer->writeln("run rm -f {$lockFile}");
        $this->deployer->run("rm -f {$lockFile}");
    }

    public function getName(): string
    {
        return 'deploy:unlock';
    }
}
