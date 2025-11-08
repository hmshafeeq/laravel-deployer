<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Deployer\Deployer;
use Symfony\Component\Yaml\Yaml;

class ClearCommand extends Command
{
    protected $signature = 'deployer:clear {environment : The deployment environment}
                            {--no-confirm : Skip confirmation prompt}';

    protected $description = 'Clear all caches and restart services on the deployment server';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $noConfirm = $this->option('no-confirm');

        // Load deploy configuration
        $deployYamlPath = base_path('.deploy/deploy.yaml');
        if (!file_exists($deployYamlPath)) {
            $deployYamlPath = base_path('deploy.yaml');
        }

        if (!file_exists($deployYamlPath)) {
            $this->error('❌ deploy.yaml not found');
            $this->info('💡 Run: php artisan laravel-deployer:install');

            return self::FAILURE;
        }

        $config = Yaml::parseFile($deployYamlPath);

        if (!isset($config[$environment])) {
            $this->error("❌ Environment '{$environment}' not found in deploy.yaml");

            return self::FAILURE;
        }

        // Show confirmation for non-local environments
        if ($environment !== 'local' && !$noConfirm) {
            $this->warn("⚠️  You are about to clear caches and restart services on {$environment}");

            if (!$this->confirm('Do you want to continue?', false)) {
                $this->info('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        $this->info("Clearing caches and restarting services on {$environment}...");
        $this->newLine();

        try {
            $deployer = new Deployer($environment, $config[$environment]);
            $deployer->loadEnvironment();

            // Get current release path
            $currentPath = $config[$environment]['deploy_path'].'/current';

            // Clear Laravel caches
            $this->info('🗑️  Clearing Laravel caches...');

            try {
                $deployer->run("cd {$currentPath} && php artisan config:clear");
                $this->info('  ✓ Config cache cleared');
            } catch (\Exception $e) {
                $this->warn('  ⚠ Config cache clear failed');
            }

            try {
                $deployer->run("cd {$currentPath} && php artisan view:clear");
                $this->info('  ✓ View cache cleared');
            } catch (\Exception $e) {
                $this->warn('  ⚠ View cache clear failed');
            }

            try {
                $deployer->run("cd {$currentPath} && php artisan route:clear");
                $this->info('  ✓ Route cache cleared');
            } catch (\Exception $e) {
                $this->warn('  ⚠ Route cache clear failed');
            }

            try {
                $deployer->run("cd {$currentPath} && php artisan cache:clear");
                $this->info('  ✓ Application cache cleared');
            } catch (\Exception $e) {
                $this->warn('  ⚠ Application cache clear failed');
            }

            // Restart queue workers
            $this->newLine();
            $this->info('🔄 Restarting queue workers...');
            try {
                $deployer->run("cd {$currentPath} && php artisan queue:restart");
                $this->info('  ✓ Queue workers restarted');
            } catch (\Exception $e) {
                $this->warn('  ⚠ Queue restart failed');
            }

            // Restart PHP-FPM (if not local)
            if ($environment !== 'local') {
                $this->newLine();
                $this->info('🔄 Restarting PHP-FPM...');
                try {
                    $phpFpmServices = $deployer->run('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""');

                    if (!empty(trim($phpFpmServices))) {
                        $services = array_filter(explode("\n", trim($phpFpmServices)));
                        foreach ($services as $service) {
                            $service = trim($service);
                            if (!empty($service)) {
                                $deployer->run("sudo systemctl restart {$service}");
                                $this->info("  ✓ Restarted {$service}");
                            }
                        }
                    } else {
                        $this->warn('  ⚠ No running PHP-FPM service found');
                    }
                } catch (\Exception $e) {
                    $this->warn('  ⚠ PHP-FPM restart failed');
                }
            }

            $this->newLine();
            $this->info('✅ System clear completed successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ System clear failed!');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
