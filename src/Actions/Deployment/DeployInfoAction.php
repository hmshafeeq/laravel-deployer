<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class DeployInfoAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $user = $this->deployer->runLocally('git config --get user.name', false);
        $branch = $this->deployer->get('branch', 'HEAD');
        $releaseName = $this->deployer->getReleaseName();

        $this->deployer->writeln("info deploying something to {$this->deployer->get('hostname')} (release {$releaseName})");
    }

    public function getName(): string
    {
        return 'deploy:info';
    }
}
