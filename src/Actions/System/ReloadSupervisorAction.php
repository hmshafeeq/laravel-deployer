<?php

namespace Shaf\LaravelDeployer\Actions\System;

use Shaf\LaravelDeployer\Actions\AbstractAction;

class ReloadSupervisorAction extends AbstractAction
{
    public function execute(): void
    {
        $this->deployer->writeln("🔄 Reloading Supervisor...");

        $this->deployer->writeln("run sudo supervisorctl reload");
        $result = $this->deployer->run("sudo supervisorctl reload");
        if (!empty($result)) {
            $this->deployer->writeln($result);
        }

        $this->deployer->writeln("✅ Supervisor reloaded");
    }

    public function getName(): string
    {
        return 'supervisor:reload';
    }
}
