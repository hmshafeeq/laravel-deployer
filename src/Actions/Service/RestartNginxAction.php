<?php

namespace Shaf\LaravelDeployer\Actions\Service;

use Shaf\LaravelDeployer\Support\Abstract\ServiceAction;

class RestartNginxAction extends ServiceAction
{
    public function execute(): void
    {
        $this->writeln('🔄 Restarting Nginx...');

        $this->writeln('run sudo systemctl restart nginx');
        $this->cmd('sudo systemctl restart nginx');

        $this->writeln('✅ Nginx restarted');
    }
}
