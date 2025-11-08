<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer;

/**
 * Base class for service management actions
 *
 * This class exists primarily for semantic clarity to identify service actions.
 * All functionality is inherited from the base Action class.
 */
abstract class ServiceAction extends Action
{
    public function __construct(
        protected Deployer $deployer
    ) {}
}
