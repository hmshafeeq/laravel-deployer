<?php

namespace Shaf\LaravelDeployer\Commands;

use Shaf\LaravelDeployer\Actions\Maintenance\ClearCachesAction;
use Shaf\LaravelDeployer\Actions\Maintenance\RestartQueueWorkersAction;
use Shaf\LaravelDeployer\Services\ServiceRestarter;

class ClearCommand extends BaseDeployerCommand
{
    protected $signature = 'deployer:clear {environment : The deployment environment}
                            {--no-confirm : Skip confirmation prompt}';

    protected $description = 'Clear all caches and restart services on the deployment server';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $noConfirm = $this->option('no-confirm');

        // Confirm operation for non-local environments
        if (!$this->confirmNonLocalOperation($environment, 'clear caches and restart services', $noConfirm)) {
            return self::SUCCESS;
        }

        $this->info("Clearing caches and restarting services on {$environment}...");
        $this->newLine();

        return $this->executeWithErrorHandling(
            fn () => $this->performClear($environment),
            '✅ System clear completed successfully!',
            '❌ System clear failed!'
        );
    }

    /**
     * Perform the clear operation
     *
     * @param string $environment
     * @return void
     */
    protected function performClear(string $environment): void
    {
        $deployer = $this->initDeployer($environment);

        // Clear Laravel caches
        ClearCachesAction::run($deployer);

        // Restart queue workers
        $this->newLine();
        RestartQueueWorkersAction::run($deployer);

        // Restart PHP-FPM (if not local)
        if ($environment !== 'local') {
            $this->newLine();
            $serviceRestarter = new ServiceRestarter($deployer);
            $serviceRestarter->restartOnly(['php-fpm'], failSilently: true);
        }
    }
}
