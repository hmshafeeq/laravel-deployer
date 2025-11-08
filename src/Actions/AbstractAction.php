<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Contracts\ActionInterface;
use Shaf\LaravelDeployer\Deployer;

abstract class AbstractAction implements ActionInterface
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
    }

    abstract public function execute(): void;

    public function getName(): string
    {
        $className = class_basename($this);
        // Convert from PascalCase to snake_case
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('Action', '', $className)));
    }
}
