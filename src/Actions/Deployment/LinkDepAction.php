<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class LinkDepAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $deployPath = $this->deployer->getDeployPath();
        $sharedPath = $this->deployer->getSharedPath();

        $this->deployer->writeln("run ln -sf {$deployPath}/.dep {$sharedPath}/storage/app/deployment");
        $this->deployer->run("ln -sf {$deployPath}/.dep {$sharedPath}/storage/app/deployment");
    }

    public function getName(): string
    {
        return 'deploy:link-dep';
    }
}
