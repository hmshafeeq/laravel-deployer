<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Actions\Deployment\RollbackDeploymentAction;
use Shaf\LaravelDeployer\Actions\Service\RestartNginxAction;
use Shaf\LaravelDeployer\Actions\Service\RestartPhpFpmAction;
use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Services\ReleaseManager;
use Symfony\Component\Yaml\Yaml;

class RollbackCommand extends Command
{
    protected $signature = 'deploy:rollback {environment : The deployment environment}
                            {--release= : Specific release to rollback to (default: previous)}
                            {--no-confirm : Skip confirmation prompt}';

    protected $description = 'Rollback to a previous release';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $specificRelease = $this->option('release');
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

        try {
            $deployer = new Deployer($environment, $config[$environment]);
            $deployer->loadEnvironment();

            // Create release manager
            $releaseManager = new ReleaseManager($deployer);

            // Get available releases
            $releases = $releaseManager->getReleases();

            if (empty($releases)) {
                $this->error('❌ No releases found to rollback to');

                return self::FAILURE;
            }

            // Get current release
            $currentRelease = $releaseManager->getCurrentRelease();

            if (!$currentRelease) {
                $this->error('❌ No current release found');

                return self::FAILURE;
            }

            // Determine target release
            if ($specificRelease) {
                if (!in_array($specificRelease, $releases)) {
                    $this->error("❌ Release '{$specificRelease}' not found");
                    $this->info('Available releases:');
                    foreach ($releases as $index => $release) {
                        $this->line('  '.($index + 1).'. '.$release);
                    }

                    return self::FAILURE;
                }
                $targetRelease = $specificRelease;
            } else {
                // Find previous release
                $currentIndex = array_search($currentRelease, $releases);
                if ($currentIndex === false || $currentIndex >= count($releases) - 1) {
                    $this->error('❌ No previous release available');

                    return self::FAILURE;
                }
                $targetRelease = $releases[$currentIndex + 1];
            }

            // Show rollback information
            $this->newLine();
            $this->warn('═══════════════════════════════════════════════════════════');
            $this->warn('                  ROLLBACK CONFIRMATION');
            $this->warn('═══════════════════════════════════════════════════════════');
            $this->newLine();
            $this->info("  Environment:     {$environment}");
            $this->info("  Current Release: {$currentRelease}");
            $this->info("  Target Release:  {$targetRelease}");
            $this->newLine();
            $this->warn('═══════════════════════════════════════════════════════════');
            $this->newLine();

            // Confirm rollback
            if (!$noConfirm) {
                if (!$this->confirm('Do you want to rollback to this release?', false)) {
                    $this->info('Rollback cancelled.');

                    return self::SUCCESS;
                }
            }

            $this->newLine();
            $this->info('🔄 Starting rollback...');
            $this->newLine();

            // Perform rollback
            RollbackDeploymentAction::run($deployer, $targetRelease);

            // Clear caches
            $this->info('🗑️  Clearing caches...');
            $currentPath = $config[$environment]['deploy_path'].'/current';

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

            // Restart queue workers
            $this->newLine();
            $this->info('🔄 Restarting queue workers...');
            try {
                $deployer->run("cd {$currentPath} && php artisan queue:restart");
                $this->info('  ✓ Queue workers restarted');
            } catch (\Exception $e) {
                $this->warn('  ⚠ Queue restart failed');
            }

            // Restart services
            if ($environment !== 'local') {
                $this->newLine();
                $this->info('🔄 Restarting services...');
                try {
                    RestartPhpFpmAction::run($deployer);
                    RestartNginxAction::run($deployer);
                } catch (\Exception $e) {
                    $this->warn('  ⚠ Service restart failed: '.$e->getMessage());
                }
            }

            $this->newLine();
            $this->info('✅ Rollback completed successfully!');
            $this->newLine();
            $this->info("Current release is now: {$targetRelease}");
            $this->newLine();

            // Show rollback migration warning
            $this->warn('⚠️  IMPORTANT: Database migrations are NOT automatically rolled back.');
            $this->info('If you need to rollback database changes, you must do so manually:');
            $this->line('  php artisan migrate:rollback --step=N');
            $this->newLine();

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ Rollback failed!');
            $this->error($e->getMessage());

            if ($this->getOutput()->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
