<?php

namespace Shaf\LaravelDeployer\Actions\System;

use Shaf\LaravelDeployer\Actions\AbstractAction;

class RestartNginxAction extends AbstractAction
{
    public function execute(): void
    {
        $this->deployer->writeln("🔄 Restarting Nginx...");

        $this->deployer->writeln("run sudo systemctl restart nginx");
        $this->deployer->run("sudo systemctl restart nginx");

        $this->deployer->writeln("✅ Nginx restarted");
    }

    public function getName(): string
    {
        return 'nginx:restart';
    }
}
