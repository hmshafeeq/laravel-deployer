<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer;

/**
 * Base class for health check actions
 *
 * This class exists primarily for semantic clarity to identify health check actions.
 * All functionality is inherited from the base Action class.
 */
abstract class HealthCheckAction extends Action
{
    public function __construct(
        protected Deployer $deployer
    ) {}
}
