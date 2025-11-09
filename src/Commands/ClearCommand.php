<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Services\DeploymentServiceFactory;

class ClearCommand extends Command
{
    protected $signature = 'deployer:clear {environment : The deployment environment}
                            {--no-confirm : Skip confirmation prompt}';

    protected $description = 'Clear all caches and restart services on the deployment server';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $noConfirm = $this->option('no-confirm');

        try {
            // Create factory and initialize for environment
            $factory = new DeploymentServiceFactory(
                base_path(),
                $this->output
            );
            $factory->createForEnvironment($environment);

            // Show confirmation for non-local environments
            if (!$factory->getConfig()->isLocal && !$noConfirm) {
                $this->warn("⚠️  You are about to clear caches and restart services on {$environment}");

                if (!$this->confirm('Do you want to continue?', false)) {
                    $this->info('Operation cancelled.');

                    return self::SUCCESS;
                }
            }

            $this->info("Clearing caches and restarting services on {$environment}...");
            $this->newLine();

            // We need to use the current release (not a specific release)
            $releaseManager = $factory->createReleaseManager();
            $currentRelease = $releaseManager->getCurrentRelease();

            if ($currentRelease) {
                $factory->setReleaseName($currentRelease);
            }

            // Create artisan task runner
            $deploymentTasks = $factory->createDeploymentTasks();

            // Clear Laravel caches
            $this->info('🗑️  Clearing Laravel caches...');

            try {
                // Use artisan commands to clear individual caches
                $deploymentTasks->artisanConfigCache();
                $this->info('  ✓ Config cache cleared');
            } catch (\Exception $e) {
                $this->warn('  ⚠ Config cache operation failed');
            }

            try {
                $deploymentTasks->artisanViewCache();
                $this->info('  ✓ View cache cleared');
            } catch (\Exception $e) {
                $this->warn('  ⚠ View cache operation failed');
            }

            try {
                $deploymentTasks->artisanRouteCache();
                $this->info('  ✓ Route cache cleared');
            } catch (\Exception $e) {
                $this->warn('  ⚠ Route cache operation failed');
            }

            // Restart queue workers
            $this->newLine();
            $this->info('🔄 Restarting queue workers...');
            try {
                $deploymentTasks->artisanQueueRestart();
                $this->info('  ✓ Queue workers restarted');
            } catch (\Exception $e) {
                $this->warn('  ⚠ Queue restart failed');
            }

            // Restart PHP-FPM (if not local)
            if (!$factory->getConfig()->isLocal) {
                $serviceTasks = $factory->createServiceTasks();

                $this->newLine();
                $this->info('🔄 Restarting PHP-FPM...');
                try {
                    $serviceTasks->restartPhpFpm();
                    $this->info('  ✓ PHP-FPM restarted');
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
