<?php

namespace Shaf\LaravelDeployer\Actions\Service;

use Shaf\LaravelDeployer\Support\Abstract\ServiceAction;

class ReloadSupervisorAction extends ServiceAction
{
    public function execute(): void
    {
        $this->writeln('🔄 Reloading Supervisor...');

        $this->writeln('run sudo supervisorctl reload');
        $result = $this->cmd('sudo supervisorctl reload');

        if (! empty($result)) {
            $this->writeln($result);
        }

        $this->writeln('✅ Supervisor reloaded');
    }
}
