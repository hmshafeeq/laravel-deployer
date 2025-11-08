<?php

namespace Shaf\LaravelDeployer\Commands;

use Shaf\LaravelDeployer\Actions\Deployment\RollbackDeploymentAction;
use Shaf\LaravelDeployer\Actions\Maintenance\ClearCachesAction;
use Shaf\LaravelDeployer\Actions\Maintenance\RestartQueueWorkersAction;
use Shaf\LaravelDeployer\Actions\Service\RestartNginxAction;
use Shaf\LaravelDeployer\Actions\Service\RestartPhpFpmAction;
use Shaf\LaravelDeployer\Services\ReleaseManager;

class RollbackCommand extends BaseDeployerCommand
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

        return $this->executeWithErrorHandling(
            fn () => $this->performRollback($environment, $specificRelease, $noConfirm),
            '✅ Rollback completed successfully!',
            '❌ Rollback failed!'
        );
    }

    /**
     * Perform the rollback operation
     *
     * @param string $environment
     * @param string|null $specificRelease
     * @param bool $noConfirm
     * @return void
     */
    protected function performRollback(string $environment, ?string $specificRelease, bool $noConfirm): void
    {
        $deployer = $this->initDeployer($environment);
        $releaseManager = new ReleaseManager($deployer);

        // Get available releases
        $releases = $releaseManager->getReleases();

        if (empty($releases)) {
            throw new \RuntimeException('No releases found to rollback to');
        }

        // Get current release
        $currentRelease = $releaseManager->getCurrentRelease();

        if (!$currentRelease) {
            throw new \RuntimeException('No current release found');
        }

        // Determine target release
        $targetRelease = $this->determineTargetRelease($releases, $currentRelease, $specificRelease);

        // Show rollback information and confirm
        $this->displayRollbackInfo($environment, $currentRelease, $targetRelease);

        if (!$noConfirm && !$this->confirm('Do you want to rollback to this release?', false)) {
            $this->info('Rollback cancelled.');

            return;
        }

        $this->newLine();
        $this->info('🔄 Starting rollback...');
        $this->newLine();

        // Perform rollback
        RollbackDeploymentAction::run($deployer, $targetRelease);

        // Clear caches
        ClearCachesAction::run($deployer, ['config', 'view', 'route']);

        // Restart queue workers
        $this->newLine();
        RestartQueueWorkersAction::run($deployer);

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
        $this->info("Current release is now: {$targetRelease}");
        $this->newLine();

        // Show rollback migration warning
        $this->warn('⚠️  IMPORTANT: Database migrations are NOT automatically rolled back.');
        $this->info('If you need to rollback database changes, you must do so manually:');
        $this->line('  php artisan migrate:rollback --step=N');
        $this->newLine();
    }

    /**
     * Determine the target release for rollback
     *
     * @param array $releases List of available releases
     * @param string $currentRelease Current release name
     * @param string|null $specificRelease Specific release requested by user
     * @return string Target release name
     * @throws \RuntimeException If target release cannot be determined
     */
    protected function determineTargetRelease(array $releases, string $currentRelease, ?string $specificRelease): string
    {
        if ($specificRelease) {
            if (!in_array($specificRelease, $releases)) {
                $this->error("❌ Release '{$specificRelease}' not found");
                $this->info('Available releases:');
                foreach ($releases as $index => $release) {
                    $this->line('  '.($index + 1).'. '.$release);
                }
                throw new \RuntimeException("Release '{$specificRelease}' not found");
            }

            return $specificRelease;
        }

        // Find previous release
        $currentIndex = array_search($currentRelease, $releases);
        if ($currentIndex === false || $currentIndex >= count($releases) - 1) {
            throw new \RuntimeException('No previous release available');
        }

        return $releases[$currentIndex + 1];
    }

    /**
     * Display rollback information
     *
     * @param string $environment
     * @param string $currentRelease
     * @param string $targetRelease
     * @return void
     */
    protected function displayRollbackInfo(string $environment, string $currentRelease, string $targetRelease): void
    {
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
    }
}
