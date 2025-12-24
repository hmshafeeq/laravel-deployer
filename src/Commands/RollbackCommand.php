<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Actions\OptimizeAction;
use Shaf\LaravelDeployer\Actions\RollbackAction;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;
use Shaf\LaravelDeployer\Services\DeploymentService;

class RollbackCommand extends Command
{
    protected $signature = 'deploy:rollback {environment : The deployment environment}
                            {--no-confirm : Skip confirmation prompt}';

    protected $description = 'Rollback to the previous release';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $noConfirm = $this->option('no-confirm');

        try {
            // Load configuration
            $config = ConfigService::load($environment, base_path(), $this->output);

            // SAFETY: Block rollback in local mode
            if ($config->isLocal) {
                $this->components->error('Rollback cannot run in local mode!');
                $this->components->error('Local mode would execute destructive commands on your local machine.');
                $this->newLine();

                return self::FAILURE;
            }

            // Initialize services
            $cmdService = new CommandService($config, $this->output);
            $deployService = new DeploymentService($config, base_path());

            // Get current and previous releases
            $current = $deployService->getCurrentRelease();
            $previous = $deployService->getPreviousRelease();

            if (! $current) {
                $this->error('❌ No current release found');

                return self::FAILURE;
            }

            if (! $previous) {
                $this->error('❌ No previous release available for rollback');

                return self::FAILURE;
            }

            // Show rollback confirmation
            if (! $noConfirm && ! $this->confirmRollback($environment, $current, $previous)) {
                $this->info('Rollback cancelled.');

                return self::SUCCESS;
            }

            $this->newLine();

            // Execute rollback
            $rollback = new RollbackAction($deployService, $cmdService, $config);
            $rollback->execute();

            // Post-rollback optimization
            $this->newLine();
            $optimize = new OptimizeAction($cmdService, $config);
            $optimize->execute();

            // Show migration warning
            $this->newLine();
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

    /**
     * Show rollback confirmation prompt
     */
    private function confirmRollback(string $environment, string $current, string $previous): bool
    {
        $this->newLine();
        $this->warn('═══════════════════════════════════════════════════════════');
        $this->warn('                  ROLLBACK CONFIRMATION');
        $this->warn('═══════════════════════════════════════════════════════════');
        $this->newLine();
        $this->info("  Environment:     {$environment}");
        $this->info("  Current Release: {$current}");
        $this->info("  Target Release:  {$previous}");
        $this->newLine();
        $this->warn('═══════════════════════════════════════════════════════════');
        $this->newLine();

        return $this->confirm('Do you want to rollback to this release?', false);
    }
}
