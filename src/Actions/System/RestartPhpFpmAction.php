<?php

namespace Shaf\LaravelDeployer\Actions\System;

use Shaf\LaravelDeployer\Actions\AbstractAction;

class RestartPhpFpmAction extends AbstractAction
{
    public function execute(): void
    {
        $this->deployer->writeln("🔄 Restarting PHP-FPM...");

        // Detect all running PHP-FPM services
        $this->deployer->writeln("run systemctl list-units --type=service --state=running | grep -o \"php[0-9.]*-fpm\" || echo \"\"");
        $phpFpmServices = $this->deployer->run('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""');

        if (!empty(trim($phpFpmServices))) {
            $lines = explode("\n", trim($phpFpmServices));
            foreach ($lines as $line) {
                $this->deployer->writeln($line);
            }

            $services = array_filter(explode("\n", trim($phpFpmServices)));

            foreach ($services as $service) {
                $service = trim($service);
                if (!empty($service)) {
                    $this->deployer->writeln("run sudo systemctl restart {$service}");
                    $this->deployer->run("sudo systemctl restart {$service}");
                    $this->deployer->writeln("✅ Restarted {$service}");
                }
            }
        } else {
            $this->deployer->writeln("⚠️  No running PHP-FPM service found", 'comment');
        }
    }

    public function getName(): string
    {
        return 'php-fpm:restart';
    }
}
