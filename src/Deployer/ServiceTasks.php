<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Concerns\ExecutesCommands;

class ServiceTasks extends BaseTaskRunner
{
    use ExecutesCommands;

    public function restartPhpFpm(): void
    {
        $this->task('php-fpm:restart', function () {
            $this->output->info("🔄 Restarting PHP-FPM...");

            // Detect all running PHP-FPM services
            $phpFpmServices = $this->run('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""');

            if (!empty(trim($phpFpmServices))) {
                $services = array_filter(explode("\n", trim($phpFpmServices)));

                foreach ($services as $service) {
                    $service = trim($service);
                    if (!empty($service)) {
                        $this->run("sudo systemctl restart {$service}");
                        $this->output->success("Restarted {$service}");
                    }
                }
            } else {
                $this->output->warning("No running PHP-FPM service found");
            }
        });
    }

    public function restartNginx(): void
    {
        $this->task('nginx:restart', function () {
            $this->output->info("🔄 Restarting Nginx...");

            $this->run("sudo systemctl restart nginx");

            $this->output->success("Nginx restarted");
        });
    }

    public function reloadSupervisor(): void
    {
        $this->task('supervisor:reload', function () {
            $this->output->info("🔄 Reloading Supervisor...");

            $this->run("sudo supervisorctl reload");

            $this->output->success("Supervisor reloaded");
        });
    }
}
