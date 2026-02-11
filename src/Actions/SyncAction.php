<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Concerns\ManagesDeploymentSteps;
use Shaf\LaravelDeployer\Concerns\ManagesLocking;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\SyncDiff;
use Shaf\LaravelDeployer\Data\SyncFileCategories;
use Shaf\LaravelDeployer\Data\SyncStats;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\DeploymentService;
use Shaf\LaravelDeployer\Services\HooksService;
use Shaf\LaravelDeployer\Services\RsyncService;
use Shaf\LaravelDeployer\Support\DeploymentSummary;
use Shaf\LaravelDeployer\Support\StepTimer;

/**
 * Sync-only deployment action.
 * Syncs files to an existing release without creating a new one.
 * Supports git-based sync strategies with smart step skipping.
 */
class SyncAction
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

    /** @var array<string> */
    private array $skippedSteps = [];

    public function __construct(
        private DeploymentService $deployment,
        private CommandService $cmd,
        private RsyncService $rsync,
        private DiffAction $diff,
        private DeploymentConfig $config,
    ) {
        $this->stepTimer = new StepTimer;
        $this->initializeHooks();
    }

    /**
     * Execute sync-only deployment.
     */
    public function execute(
        string $releaseName,
        string $releasePath,
        bool $skipAssetBuild = false,
        ?string $filesFromPath = null,
        ?SyncFileCategories $categories = null,
    ): void {
        $startTime = microtime(true);

        $this->releaseName = $releaseName;
        $this->releasePath = $releasePath;

        // Capture git info
        $this->captureGitInfo();

        $this->cmd->info("Starting sync to {$this->config->environment->value}");
        $this->cmd->info("   Using existing release: {$releaseName}");
        $this->showGitInfo();
        $this->cmd->newLine();

        // 1. Lock deployment
        $this->lockDeployment();

        try {
            // 2. Build assets locally (if not skipping)
            if ($this->shouldRunStep('assets:build', $skipAssetBuild, $categories)) {
                $this->stepTimer->start('assets:build');
                $this->buildAssets();
                $this->stepTimer->end('assets:build');
            } else {
                $this->recordSkip('assets:build');
            }

            // 3. Show sync differences
            $currentPath = "{$this->config->deployPath}/current";
            if ($this->cmd->symlinkExists($currentPath)) {
                $this->syncDiff = $this->diff->showRemoteDiff($currentPath);
            }

            // 4. Sync files to existing release
            $this->stepTimer->start('files:sync');
            $this->syncFilesToRelease($filesFromPath);
            $this->stepTimer->end('files:sync');

            // 5. Verify critical assets
            $this->verifyAssets();

            // 6. Ensure storage structure exists
            $this->ensureStorageStructureForSyncOnly();

            // 7. Clear existing caches
            $this->stepTimer->start('cache:clear');
            $this->clearCachesForSyncOnly();
            $this->stepTimer->end('cache:clear');

            // 8. Run composer install
            if ($this->shouldRunComposer($categories)) {
                $this->stepTimer->start('composer:install');
                $this->installComposerForSyncOnly();
                $this->stepTimer->end('composer:install');
            } else {
                $this->recordSkip('composer:install');
            }

            // 9. Fix permissions
            if ($this->shouldFixPermissions($categories)) {
                $this->stepTimer->start('permissions:fix');
                $this->fixPermissions();
                $this->stepTimer->end('permissions:fix');
            } else {
                $this->recordSkip('permissions:fix');
            }

            // 10. Run migrations
            if ($this->shouldRunMigrations($categories)) {
                $this->stepTimer->start('artisan:migrate');
                $this->runMigrations();
                $this->stepTimer->end('artisan:migrate');
            } else {
                $this->recordSkip('artisan:migrate');
            }

            // 11. Run optimization commands
            $this->stepTimer->start('optimize:release');
            $this->runBeforeSymlinkCommands();
            $this->stepTimer->end('optimize:release');

            // 12. Create storage symlink (in case it was removed)
            $this->stepTimer->start('storage:link');
            $this->createStorageLink();
            $this->stepTimer->end('storage:link');

            // Calculate total deployment time
            $this->duration = microtime(true) - $startTime;

        } finally {
            // Cleanup SSH sockets
            $this->cleanupSshSockets();

            // Always unlock
            $this->unlockDeployment();
        }
    }

    /**
     * Show sync-only deployment summary
     */
    public function showSummary(): void
    {
        $summary = DeploymentSummary::create($this->cmd->getOutput(), $this->config);

        $summary->showSyncOnlySuccess(
            releaseName: $this->releaseName,
            duration: $this->duration,
            syncDiff: $this->syncDiff,
            syncStats: $this->syncStats,
            migrationsRun: $this->migrationsRun,
            stepTimings: $this->stepTimer->getTimings(),
            gitInfo: $this->getGitInfo(),
            warnings: $this->warnings
        );
    }

    /**
     * Get the release name
     */
    public function getReleaseName(): string
    {
        return $this->releaseName;
    }

    /**
     * Get the deployment duration
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * Get skipped steps
     *
     * @return array<string>
     */
    public function getSkippedSteps(): array
    {
        return $this->skippedSteps;
    }

    private function shouldRunStep(string $step, bool $isExplicitlySkipped, ?SyncFileCategories $categories): bool
    {
        if ($isExplicitlySkipped) {
            return false;
        }

        if ($this->config->isLocal) {
            return false;
        }

        // Without categories (full rsync), always run
        if ($categories === null) {
            return true;
        }

        return match ($step) {
            'assets:build' => $categories->hasFrontendAssets,
            default => true,
        };
    }

    private function shouldRunComposer(?SyncFileCategories $categories): bool
    {
        if ($categories === null) {
            return true;
        }

        return $categories->hasComposerLock;
    }

    private function shouldFixPermissions(?SyncFileCategories $categories): bool
    {
        if ($categories === null) {
            return true;
        }

        return $categories->hasNewFiles;
    }

    private function shouldRunMigrations(?SyncFileCategories $categories): bool
    {
        if ($categories === null) {
            return true;
        }

        return $categories->hasMigrations;
    }

    private function recordSkip(string $step): void
    {
        $this->skippedSteps[] = $step;
        $this->cmd->info("  Skipping {$step} (no relevant changes)");
    }
}
