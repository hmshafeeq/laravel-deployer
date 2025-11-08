<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer\Deployer;

/**
 * Base class for deployment-related actions
 *
 * This class exists primarily for semantic clarity to identify deployment actions.
 * All functionality is inherited from the base Action class.
 */
abstract class DeploymentAction extends Action
{
    public function __construct(
        protected Deployer $deployer
    ) {}
}
