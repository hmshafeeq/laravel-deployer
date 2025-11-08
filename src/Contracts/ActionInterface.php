<?php

namespace Shaf\LaravelDeployer\Contracts;

use Shaf\LaravelDeployer\Deployer;

interface ActionInterface
{
    /**
     * Execute the action
     */
    public function execute(): void;

    /**
     * Get the action name for logging
     */
    public function getName(): string;
}
