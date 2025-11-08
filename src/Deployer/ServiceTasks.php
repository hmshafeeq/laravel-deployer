<?php

namespace Shaf\LaravelDeployer\Deployer;

class ServiceTasks
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
    }

    public function restartPhpFpm(): void
    {
        $this->deployer->task('php-fpm:restart', function ($deployer) {
            $deployer->writeln("🔄 Restarting PHP-FPM...");

            // Detect all running PHP-FPM services
            $deployer->writeln("run systemctl list-units --type=service --state=running | grep -o \"php[0-9.]*-fpm\" || echo \"\"");
            $phpFpmServices = $deployer->run('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""');

            if (!empty(trim($phpFpmServices))) {
                $lines = explode("\n", trim($phpFpmServices));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }

                $services = array_filter(explode("\n", trim($phpFpmServices)));

                foreach ($services as $service) {
                    $service = trim($service);
                    if (!empty($service)) {
                        $deployer->writeln("run sudo systemctl restart {$service}");
                        $deployer->run("sudo systemctl restart {$service}");
                        $deployer->writeln("✅ Restarted {$service}");
                    }
                }
            } else {
                $deployer->writeln("⚠️  No running PHP-FPM service found", 'comment');
            }
        });
    }

    public function restartNginx(): void
    {
        $this->deployer->task('nginx:restart', function ($deployer) {
            $deployer->writeln("🔄 Restarting Nginx...");

            $deployer->writeln("run sudo systemctl restart nginx");
            $deployer->run("sudo systemctl restart nginx");

            $deployer->writeln("✅ Nginx restarted");
        });
    }

    public function reloadSupervisor(): void
    {
        $this->deployer->task('supervisor:reload', function ($deployer) {
            $deployer->writeln("🔄 Reloading Supervisor...");

            $deployer->writeln("run sudo supervisorctl reload");
            $result = $deployer->run("sudo supervisorctl reload");
            if (!empty($result)) {
                $deployer->writeln($result);
            }

            $deployer->writeln("✅ Supervisor reloaded");
        });
    }
}
