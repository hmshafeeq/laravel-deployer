<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Concerns\ManagesDeploymentSteps;
use Shaf\LaravelDeployer\Concerns\ManagesLocking;
use Shaf\LaravelDeployer\Constants\Paths;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\DeploymentReceipt;
use Shaf\LaravelDeployer\Data\ReleaseInfo;
use Shaf\LaravelDeployer\Data\SyncDiff;
use Shaf\LaravelDeployer\Data\SyncStats;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\DeploymentService;
use Shaf\LaravelDeployer\Services\HooksService;
use Shaf\LaravelDeployer\Services\ReceiptService;
use Shaf\LaravelDeployer\Services\RsyncService;
use Shaf\LaravelDeployer\Support\DeploymentSummary;
use Shaf\LaravelDeployer\Support\StepTimer;

/**
 * Complete deployment workflow action.
 * Handles all steps from locking to cleanup in a single, cohesive operation.
 */
class DeployAction
{
    use ManagesDeploymentSteps;
    use ManagesLocking;

    private string $releaseName;

    private string $releasePath;

    private ?SyncDiff $syncDiff = null;

    private ?SyncStats $syncStats = null;

    private float $duration = 0;

    private ?HooksService $hooks = null;

    private StepTimer $stepTimer;

    private ?string $gitCommitHash = null;

    private ?string $gitCommitMessage = null;

    private ?string $gitAuthor = null;

    private int $migrationsRun = 0;

    /** @var array<array{category: string, message: string}> */
    private array $warnings = [];

    public function __construct(
        private DeploymentService $deployment,
        private CommandService $cmd,
        private RsyncService $rsync,
        private DiffAction $diff,
        private DeploymentConfig $config,
        private ?HealthCheckAction $healthCheck = null,
        private ?ReceiptService $receiptService = null
    ) {
        $this->stepTimer = new StepTimer;
        $this->initializeHooks();
    }

    /**
     * Execute the complete deployment workflow
     */
    public function execute(): void
    {
        $startTime = microtime(true);

        // Capture git info before starting
        $this->captureGitInfo();

        $this->cmd->info("🚀 Starting deployment to {$this->config->environment->value}");

        // Show git info if available
        $this->showGitInfo();

        $this->cmd->newLine();

        // Run before:deploy hooks
        $this->runHook('before:deploy');

        // 1. Check and lock deployment
        $this->lockDeployment();

        try {
            // 2. Setup deployment structure
            $this->stepTimer->start('deployment:setup');
            $this->setupDeploymentStructure();
            $this->stepTimer->end('deployment:setup');

            // Run after:setup hooks
            $this->runHook('after:setup');

            // 3. Generate and create release
            $this->stepTimer->start('release:create');
            $this->createRelease();
            $this->stepTimer->end('release:create');

            // Set release path for hooks
            $this->hooks?->setReleasePath($this->releasePath);

            // 4. Build assets locally (if not local deployment and not skipping build folder)
            $skipAssetBuild = $this->config->skipAssetBuild
                || in_array('public/build/', $this->config->rsyncExcludes, true);
            if (! $this->config->isLocal && ! $skipAssetBuild) {
                $this->runHook('before:build');
                $this->stepTimer->start('assets:build');
                $this->buildAssets();
                $this->stepTimer->end('assets:build');
                $this->runHook('after:build');
            } elseif ($skipAssetBuild) {
                $this->cmd->info('Skipping asset build');
            }

            // 5. Show sync differences (compare against current release for accuracy)
            if ($this->config->showDiff) {
                $currentPath = $this->deployment->getCurrentPath();
                if ($this->cmd->symlinkExists($currentPath)) {
                    // Compare against current release for accurate diff
                    $this->syncDiff = $this->diff->showRemoteDiff($currentPath);
                } else {
                    // First deployment - use local temp comparison
                    $this->syncDiff = $this->diff->show();
                }
            }

            // 6. Confirm changes
            if ($this->config->confirmChanges) {
                if (! $this->confirmDeploymentChanges()) {
                    throw new \Exception('Deployment cancelled by user');
                }
            }

            // 7. Sync files to server
            $this->runHook('before:sync');
            $this->stepTimer->start('files:sync');
            $this->syncFilesForDeploy();
            $this->stepTimer->end('files:sync');
            $this->runHook('after:sync');

            // 7.5. Verify critical assets were deployed (optional)
            $this->verifyAssets();

            // 8. Create shared symlinks
            $this->stepTimer->start('shared:link');
            $this->createSharedLinks();
            $this->stepTimer->end('shared:link');

            // 9. Fix shared log file permissions
            $this->fixSharedLogPermissions();

            // 10. Install composer dependencies
            $this->runHook('before:composer');
            $this->stepTimer->start('composer:install');
            $this->installComposerDependencies();
            $this->stepTimer->end('composer:install');
            $this->runHook('after:composer');

            // 11. Fix all permissions in single SSH batch
            $this->stepTimer->start('permissions:fix');
            $this->fixPermissions();
            $this->stepTimer->end('permissions:fix');

            // 12. Run database migrations
            if (! $this->config->skipMigrations) {
                $this->runHook('before:migrate');
                $this->stepTimer->start('artisan:migrate');
                $this->runMigrations();
                $this->stepTimer->end('artisan:migrate');
                $this->runHook('after:migrate');
            } else {
                $this->cmd->info('Skipping database migrations (interactive mode)');
            }

            // 13. Link .dep directory
            $this->linkDepDirectory();

            // 14. Run optimization commands (critical - aborts on failure)
            $this->stepTimer->start('optimize:release');
            $this->runBeforeSymlinkCommands();
            $this->stepTimer->end('optimize:release');

            // 15. Create storage symlink
            $this->stepTimer->start('storage:link');
            $this->createStorageLink();
            $this->stepTimer->end('storage:link');

            // 16. Symlink current release
            $this->runHook('before:symlink');
            $this->stepTimer->start('release:symlink');
            $this->symlinkRelease();
            $this->stepTimer->end('release:symlink');
            $this->runHook('after:symlink');

            // 17. Log deployment success (moved here - only log after symlink succeeds)
            $this->logDeploymentSuccess();

            // 18. Verify deployment health
            if ($this->healthCheck !== null) {
                $this->stepTimer->start('health:verify');
                $this->verifyDeploymentHealth();
                $this->stepTimer->end('health:verify');
            }

            // 19. Cleanup old releases
            $this->stepTimer->start('cleanup:releases');
            $this->cleanupOldReleases();
            $this->stepTimer->end('cleanup:releases');

            // 20. Run post-deployment hooks
            $this->stepTimer->start('hooks:post-deploy');
            $this->runPostDeploymentHooks();
            $this->stepTimer->end('hooks:post-deploy');

            // Calculate total deployment time
            $this->duration = microtime(true) - $startTime;

            // 21. Generate deployment receipt
            $this->generateReceipt(success: true);

            // Run after:deploy hooks
            $this->runHook('after:deploy');

        } catch (\Exception $e) {
            // Run on:failure hooks
            $this->runHook('on:failure');
            throw $e;
        } finally {
            // Cleanup SSH control sockets to prevent stale connections
            $this->cleanupSshSockets();

            // Always unlock deployment, even if there's an error
            $this->unlockDeployment();
        }
    }

    /**
     * Setup deployment directory structure
     */
    private function setupDeploymentStructure(): void
    {
        $this->cmd->task('deployment:setup');

        $deployPath = $this->config->deployPath;
        $escapedDeployPath = CommandService::escapePath($deployPath);

        $directories = [
            $deployPath,
            "{$deployPath}/".Paths::RELEASES_DIR,
            "{$deployPath}/".Paths::SHARED_DIR,
            "{$deployPath}/".Paths::DEP_DIR,
            "{$deployPath}/".Paths::SHARED_DIR.'/storage',
            "{$deployPath}/".Paths::SHARED_DIR.'/storage/app',
            "{$deployPath}/".Paths::SHARED_DIR.'/storage/framework',
            "{$deployPath}/".Paths::SHARED_DIR.'/storage/framework/cache',
            "{$deployPath}/".Paths::SHARED_DIR.'/storage/framework/sessions',
            "{$deployPath}/".Paths::SHARED_DIR.'/storage/framework/views',
            "{$deployPath}/".Paths::SHARED_DIR.'/storage/logs',
        ];

        $escapedDirs = array_map([CommandService::class, 'escapePath'], $directories);
        $sharedEnvPath = CommandService::escapePath("{$deployPath}/".Paths::SHARED_DIR.'/.env');

        $commands = [
            'mkdir -p '.implode(' ', $escapedDirs),
            "touch {$sharedEnvPath}",
        ];

        if ($this->config->enforceSetgid) {
            $escapedSharedPath = CommandService::escapePath("{$deployPath}/".Paths::SHARED_DIR);
            $commands[] = "find {$escapedSharedPath} -type d -exec chmod g+s {} \\; 2>/dev/null || true";
        }

        $this->cmd->runBatch($commands);

        $this->cmd->success('Deployment structure ready');
    }

    /**
     * Generate release name and create release directory
     */
    private function createRelease(): void
    {
        $this->cmd->task('release:create');

        $this->releaseName = $this->deployment->generateReleaseName();
        $this->deployment->setCurrentReleaseName($this->releaseName);

        $this->releasePath = $this->deployment->getReleasePath($this->releaseName);

        $this->cmd->success("Release {$this->releaseName} created");
    }

    /**
     * Confirm deployment changes with user
     */
    private function confirmDeploymentChanges(): bool
    {
        $diff = $this->syncDiff ?? new SyncDiff;

        return $this->diff->confirmChanges($diff);
    }

    /**
     * Sync files for full deployment (with copy from previous release)
     */
    private function syncFilesForDeploy(): void
    {
        if ($this->config->showUploadProgress) {
            $diff = $this->syncDiff ?? new SyncDiff;
            $this->diff->showUploadProgress($diff);
        }

        $this->cmd->task('files:sync');
        $this->cmd->info('Syncing files to server...');

        // Copy previous release before rsync
        $this->copyPreviousRelease();

        if ($this->syncDiff !== null && $this->syncDiff->isEmpty()) {
            $this->cmd->debug('Note: Diff was calculated against current release; seeded copy may show different transfer activity');
        }

        // Pass sync diff and output for progress bar
        $this->rsync->setSyncDiff($this->syncDiff);
        $this->rsync->setOutput($this->cmd->getOutput());

        $this->rsync->sync($this->releasePath);

        // Capture actual sync stats from rsync (not theoretical diff)
        $this->syncStats = SyncStats::fromRsync($this->rsync, $this->syncDiff);

        $this->cmd->success('Files synced successfully');

        if ($this->config->showUploadProgress) {
            $this->diff->showUploadComplete();
        }
    }

    /**
     * Copy previous release to new release directory.
     */
    private function copyPreviousRelease(): void
    {
        $previousRelease = $this->deployment->getCurrentRelease();
        if (! $previousRelease) {
            $this->cmd->debug('No previous release found, skipping copy');

            return;
        }

        $previousReleasePath = $this->deployment->getReleasePath($previousRelease);

        if (! $this->cmd->directoryExists($previousReleasePath)) {
            $this->cmd->debug('Previous release directory does not exist, skipping copy');

            return;
        }

        $this->cmd->info('Copying previous release...');

        $escapedPrevious = CommandService::escapePath($previousReleasePath);
        $escapedNew = CommandService::escapePath($this->releasePath);

        if ($this->config->copyVendor) {
            $this->cmd->remote(
                "cp -rp {$escapedPrevious}/. {$escapedNew}/ && ".
                "rm -rf {$escapedNew}/node_modules && ".
                "rm -rf {$escapedNew}/bootstrap/cache && ".
                "mkdir -p {$escapedNew}/bootstrap/cache"
            );
            $this->cmd->success('Previous release copied (with vendor)');
        } else {
            $this->cmd->remote(
                "cp -rp {$escapedPrevious}/. {$escapedNew}/ && ".
                "rm -rf {$escapedNew}/vendor && ".
                "rm -rf {$escapedNew}/node_modules && ".
                "rm -rf {$escapedNew}/bootstrap/cache && ".
                "mkdir -p {$escapedNew}/bootstrap/cache"
            );
            $this->cmd->success('Previous release copied (without vendor)');
        }
    }

    /**
     * Link .dep directory to release
     */
    private function linkDepDirectory(): void
    {
        $deployPath = $this->config->deployPath;
        $escapedDepPath = CommandService::escapePath("{$deployPath}/.dep");
        $escapedReleaseDep = CommandService::escapePath("{$this->releasePath}/.dep");

        $this->cmd->remote("ln -nfs {$escapedDepPath} {$escapedReleaseDep}");
    }

    /**
     * Symlink current to new release
     */
    private function symlinkRelease(): void
    {
        $this->cmd->task('release:symlink');

        $currentPath = $this->deployment->getCurrentPath();
        $escapedReleasePath = CommandService::escapePath($this->releasePath);
        $escapedCurrentPath = CommandService::escapePath($currentPath);
        $escapedLatestRelease = CommandService::escapePath("{$this->config->deployPath}/.dep/latest_release");

        $this->cmd->runBatch([
            "ln -nfs {$escapedReleasePath} {$escapedCurrentPath}",
            "echo {$this->releaseName} > {$escapedLatestRelease}",
        ]);

        $this->cmd->success('Release symlinked as current');
    }

    /**
     * Verify deployment health after symlink
     */
    private function verifyDeploymentHealth(): void
    {
        if ($this->healthCheck === null) {
            return;
        }

        $this->healthCheck->verifyDeployment();
    }

    /**
     * Cleanup old releases (keep configured number)
     */
    private function cleanupOldReleases(): void
    {
        $this->cmd->task('cleanup:releases');

        $keepReleases = $this->config->keepReleases ?? 3;
        $deployPath = $this->config->deployPath;
        $escapedReleasesPath = CommandService::escapePath("{$deployPath}/releases");

        $this->cmd->info("Cleaning up old releases (keeping {$keepReleases})...");

        try {
            $output = $this->cmd->remote(
                "cd {$escapedReleasesPath} && ls -t | tail -n +".($keepReleases + 1).' | xargs -r rm -rf 2>/dev/null || true; '.
                "ls -1 {$escapedReleasesPath} | wc -l"
            );

            $remaining = trim($output);
            $this->cmd->success("Cleanup complete. {$remaining} releases remain");
        } catch (\Exception $e) {
            $this->cmd->warning('Could not fully clean up old releases (permission issues). Manual cleanup may be needed.');
        }
    }

    /**
     * Log deployment success
     */
    private function logDeploymentSuccess(): void
    {
        $deployPath = $this->config->deployPath;
        $escapedLogFile = CommandService::escapePath("{$deployPath}/.dep/deploy.log");

        $timestamp = date('Y-m-d H:i:s');
        $user = $this->deployment->getUser();
        $logEntry = "[{$timestamp}] {$user} deployed release {$this->releaseName} to {$this->config->environment->value}";
        $escapedLogEntry = escapeshellarg($logEntry);

        $this->cmd->remote("echo {$escapedLogEntry} >> {$escapedLogFile}");

        $releaseInfo = new ReleaseInfo(
            name: $this->releaseName,
            createdAt: new \DateTimeImmutable,
            user: $user,
            branch: $this->config->branch
        );

        $this->deployment->logRelease($releaseInfo);
    }

    /**
     * Generate and save a deployment receipt
     */
    private function generateReceipt(bool $success = true, ?string $errorMessage = null): void
    {
        if ($this->receiptService === null) {
            return;
        }

        $this->cmd->task('receipt:generate');

        $receipt = DeploymentReceipt::fromDeployment(
            release: $this->releaseName,
            environment: $this->config->environment->value,
            deployedBy: $this->deployment->getUser(),
            duration: $this->duration,
            syncStats: $this->syncStats,
            postDeployCommands: $this->config->postDeployCommands,
            success: $success,
            errorMessage: $errorMessage
        );

        $this->receiptService->save($receipt);
        $this->cmd->success('Deployment receipt saved');
    }

    /**
     * Get the release name
     */
    public function getReleaseName(): string
    {
        return $this->releaseName;
    }

    /**
     * Get the deployment duration in seconds
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * Get the deployment duration formatted as human-readable string
     */
    public function getFormattedDuration(): string
    {
        return \format_duration($this->duration);
    }

    /**
     * Get the sync diff for summary
     */
    public function getSyncDiff(): ?SyncDiff
    {
        return $this->syncDiff;
    }

    /**
     * Show the deployment summary dashboard
     */
    public function showSummary(): void
    {
        $summary = DeploymentSummary::create($this->cmd->getOutput(), $this->config);

        $url = null;
        if ($this->config->healthCheckUrl) {
            $scheme = $this->config->environment->isProduction() ? 'https' : 'http';
            $url = "{$scheme}://{$this->config->hostname}";
        }

        $summary->showSuccess(
            releaseName: $this->releaseName,
            duration: $this->duration,
            syncDiff: $this->syncDiff,
            syncStats: $this->syncStats,
            migrationsRun: $this->migrationsRun,
            url: $url,
            stepTimings: $this->stepTimer->getTimings(),
            gitInfo: $this->getGitInfo(),
            warnings: $this->warnings
        );
    }

    /**
     * Get the step timer for external access
     */
    public function getStepTimer(): StepTimer
    {
        return $this->stepTimer;
    }
}
