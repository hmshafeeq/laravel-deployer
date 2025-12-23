<?php

namespace Shaf\LaravelDeployer\Actions\Service;

use Shaf\LaravelDeployer\Support\Abstract\ServiceAction;

class RestartPhpFpmAction extends ServiceAction
{
    public function execute(): void
    {
        $this->writeln('🔄 Restarting PHP-FPM...');

        // Detect all running PHP-FPM services
        $this->writeln('run systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""');
        $phpFpmServices = $this->cmd('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""');

        if (empty(trim($phpFpmServices))) {
            $this->writeln('⚠️  No running PHP-FPM service found', 'comment');

            return;
        }

        $this->restartServices($phpFpmServices);
    }

    /**
     * Restart all detected PHP-FPM services
     */
    protected function restartServices(string $phpFpmServices): void
    {
        $lines = explode("\n", trim($phpFpmServices));
        foreach ($lines as $line) {
            $this->writeln($line);
        }

        $services = array_filter(explode("\n", trim($phpFpmServices)));

        foreach ($services as $service) {
            $service = trim($service);
            if (! empty($service)) {
                $this->writeln("run sudo systemctl restart {$service}");
                $this->cmd("sudo systemctl restart {$service}");
                $this->writeln("✅ Restarted {$service}");
            }
        }
    }
}
