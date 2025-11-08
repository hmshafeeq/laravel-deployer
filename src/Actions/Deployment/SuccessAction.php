<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class SuccessAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $this->deployer->writeln("info successfully deployed!");
    }

    public function getName(): string
    {
        return 'deploy:success';
    }
}
