<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class BuildAssetsAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $this->deployer->runLocalCommand('npm run build');
    }

    public function getName(): string
    {
        return 'build:assets';
    }
}
