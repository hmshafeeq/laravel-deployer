<?php

namespace Shaf\LaravelDeployer\Actions\Database;

use Shaf\LaravelDeployer\Actions\AbstractAction;
use Shaf\LaravelDeployer\Services\DatabaseService;

abstract class AbstractDatabaseAction extends AbstractAction
{
    protected DatabaseService $databaseService;

    public function __construct(\Shaf\LaravelDeployer\Deployer $deployer)
    {
        parent::__construct($deployer);
        $this->databaseService = new DatabaseService($deployer);
    }
}
