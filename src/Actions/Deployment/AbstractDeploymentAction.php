<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Actions\AbstractAction;
use Shaf\LaravelDeployer\Services\ReleaseService;

abstract class AbstractDeploymentAction extends AbstractAction
{
    protected ReleaseService $releaseService;

    public function __construct(\Shaf\LaravelDeployer\Deployer $deployer)
    {
        parent::__construct($deployer);
        $this->releaseService = new ReleaseService($deployer);
    }
}
