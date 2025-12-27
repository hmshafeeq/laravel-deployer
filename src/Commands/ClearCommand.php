<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;
use Shaf\LaravelDeployer\Services\DeploymentService;

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
            // Load configuration
            $config = ConfigService::load($environment, base_path(), $this->output);

            // Initialize services
            $cmd = new CommandService($config, $this->output);
            $deployment = new DeploymentService($config, $cmd, base_path());

            // Show confirmation for non-local environments
            if (! $config->isLocal && ! $noConfirm) {
                $this->warn("⚠️  You are about to clear caches and restart services on {$environment}");

                if (! $this->confirm('Do you want to continue?', false)) {
                    $this->info('Operation cancelled.');

                    return self::SUCCESS;
                }
            }

            $this->info("Clearing caches and restarting services on {$environment}...");
            $this->newLine();

            // Get current release path
            $currentPath = $deployment->getCurrentPath();

            // Clear Laravel caches
            $this->info('🗑️  Clearing Laravel caches...');

            $results = [
                'config' => $this->runArtisanCommand($cmd, $currentPath, 'config:clear'),
                'view' => $this->runArtisanCommand($cmd, $currentPath, 'view:clear'),
                'route' => $this->runArtisanCommand($cmd, $currentPath, 'route:clear'),
                'queue' => $this->runArtisanCommand($cmd, $currentPath, 'queue:restart'),
            ];

            // Display results
            $this->info($results['config'] ? '  ✓ Config cache cleared' : '  ⚠ Config cache operation failed');
            $this->info($results['view'] ? '  ✓ View cache cleared' : '  ⚠ View cache operation failed');
            $this->info($results['route'] ? '  ✓ Route cache cleared' : '  ⚠ Route cache operation failed');

            // Restart queue workers
            $this->newLine();
            $this->info('🔄 Restarting queue workers...');
            $this->info($results['queue'] ? '  ✓ Queue workers restarted' : '  ⚠ Queue restart failed');

            // Restart PHP-FPM (if not local)
            if (! $config->isLocal) {
                $this->newLine();
                $this->info('🔄 Restarting PHP-FPM...');

                try {
                    $cmd->restartPhpFpm();
                } catch (\Exception $e) {
                    $this->warn('  ⚠ PHP-FPM restart failed: '.$e->getMessage());
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

    /**
     * Run an artisan command on the remote server
     */
    private function runArtisanCommand(CommandService $cmd, string $path, string $command): bool
    {
        try {
            $cmd->artisan($command, $path);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
