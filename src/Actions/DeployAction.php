<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Constants\Paths;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\ReleaseInfo;
use Shaf\LaravelDeployer\Data\SyncDiff;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\DeploymentService;
use Shaf\LaravelDeployer\Services\RsyncService;

/**
 * Complete deployment workflow action.
 * Handles all steps from locking to cleanup in a single, cohesive operation.
 */
class DeployAction
{
    private string $releaseName;

    private string $releasePath;

    private ?SyncDiff $syncDiff = null;

    private float $duration = 0;

    public function __construct(
        private DeploymentService $deployment,
        private CommandService $cmd,
        private RsyncService $rsync,
        private DiffAction $diff,
        private DeploymentConfig $config
    ) {
        $this->deployment->setCommandService($cmd);
    }

    /**
     * Execute the complete deployment workflow
     */
    public function execute(): void
    {
        $startTime = microtime(true);

        $this->cmd->info("🚀 Starting deployment to {$this->config->environment->value}");
        $this->cmd->newLine();

        // 1. Check and lock deployment
        $this->lockDeployment();

        try {
            // 2. Setup deployment structure
            $this->setupDeploymentStructure();

            // 3. Generate and create release
            $this->createRelease();

            // 4. Build assets locally (if not local deployment)
            if (! $this->config->isLocal) {
                $this->buildAssets();
            }

            // 5. Show sync differences
            if ($this->config->showDiff) {
                $this->syncDiff = $this->diff->show();
            }

            // 6. Confirm changes
            if ($this->config->confirmChanges) {
                if (! $this->confirmDeploymentChanges()) {
                    throw new \Exception('Deployment cancelled by user');
                }
            }

            // 7. Sync files to server
            $this->syncFilesWithProgress();

            // 8. Create shared symlinks
            $this->createSharedLinks();

            // 9. Set writable permissions
            $this->setWritablePermissions();

            // 10. Install composer dependencies
            $this->installComposerDependencies();

            // 11. Fix module permissions
            $this->fixModulePermissions();

            // 12. Run database migrations
            $this->runMigrations();

            // 13. Link .dep directory
            $this->linkDepDirectory();

            // 14. Symlink current release
            $this->symlinkRelease();

            // 15. Cleanup old releases
            $this->cleanupOldReleases();

            // 16. Log deployment success
            $this->logDeploymentSuccess();

            // 17. Run post-deployment hooks
            $this->runPostDeploymentHooks();

            // Calculate total deployment time
            $this->duration = microtime(true) - $startTime;
            $formattedDuration = \format_duration($this->duration);

            $this->cmd->newLine();
            $this->cmd->success('✅ Deployment completed successfully!');
            $this->cmd->success("🎉 Release {$this->releaseName} is now live on {$this->config->environment->value}");
            $this->cmd->success("⏱️  Total time: {$formattedDuration}");

        } finally {
            // Always unlock deployment, even if there's an error
            $this->unlockDeployment();
        }
    }

    /**
     * Lock deployment to prevent concurrent deployments
     */
    private function lockDeployment(): void
    {
        $this->cmd->task('deployment:lock');
        $this->deployment->check();
        $this->deployment->lock();
        $this->cmd->success('Deployment locked');
    }

    /**
     * Setup deployment directory structure
     */
    private function setupDeploymentStructure(): void
    {
        $this->cmd->task('deployment:setup');

        $deployPath = $this->config->deployPath;

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

        foreach ($directories as $dir) {
            $this->cmd->remote("mkdir -p {$dir}");
        }

        // Ensure .env exists in shared
        $this->cmd->remote("touch {$deployPath}/".Paths::SHARED_DIR.'/.env');

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

        $this->cmd->remote("mkdir -p {$this->releasePath}");

        $this->cmd->success("Release {$this->releaseName} created");
    }

    /**
     * Build frontend assets locally
     */
    private function buildAssets(): void
    {
        $this->cmd->task('assets:build');
        $this->cmd->info('Building frontend assets...');

        try {
            $this->cmd->local('npm run build');
            $this->cmd->success('Assets built successfully');
        } catch (\Exception $e) {
            $this->cmd->warning('Asset build failed (continuing anyway): '.$e->getMessage());
        }
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
     * Sync files to server with progress indicators
     */
    private function syncFilesWithProgress(): void
    {
        if ($this->config->showUploadProgress) {
            $diff = $this->syncDiff ?? new SyncDiff;
            $this->diff->showUploadProgress($diff);
        }

        $this->syncFiles();

        if ($this->config->showUploadProgress) {
            $this->diff->showUploadComplete();
        }
    }

    /**
     * Sync files to server using rsync
     */
    private function syncFiles(): void
    {
        $this->cmd->task('files:sync');
        $this->cmd->info('Syncing files to server...');

        // For local deployments, just use the path; RsyncService handles the rest
        $this->rsync->sync($this->releasePath);

        $this->cmd->success('Files synced successfully');
    }

    /**
     * Create symlinks to shared directories
     */
    private function createSharedLinks(): void
    {
        $this->cmd->task('shared:link');

        $sharedPath = $this->deployment->getSharedPath();

        // Remove storage directory and link to shared
        $this->cmd->remote("rm -rf {$this->releasePath}/storage");
        $this->cmd->remote("ln -nfs {$sharedPath}/storage {$this->releasePath}/storage");

        // Link .env file
        $this->cmd->remote("ln -nfs {$sharedPath}/.env {$this->releasePath}/.env");

        $this->cmd->success('Shared directories linked');
    }

    /**
     * Set writable permissions on required directories
     */
    private function setWritablePermissions(): void
    {
        $this->cmd->task('permissions:writable');

        $writableDirs = [
            "{$this->releasePath}/bootstrap/cache",
            "{$this->releasePath}/storage",
        ];

        foreach ($writableDirs as $dir) {
            // Create directory if it doesn't exist (e.g., bootstrap/cache is often gitignored)
            $this->cmd->remote("mkdir -p {$dir}");
            $this->cmd->remote("chmod -R 775 {$dir} 2>/dev/null || true");
        }

        $this->cmd->success('Writable permissions set');
    }

    /**
     * Install composer dependencies
     */
    private function installComposerDependencies(): void
    {
        $this->cmd->task('composer:install');
        $this->cmd->info('Installing Composer dependencies...');

        $composerOptions = $this->config->composerOptions ?? '--verbose --prefer-dist --no-interaction --no-scripts --no-plugins --no-dev --optimize-autoloader';

        $this->cmd->remote("cd {$this->releasePath} && composer install {$composerOptions}");

        $this->cmd->success('Composer dependencies installed');
    }

    /**
     * Fix module permissions
     */
    private function fixModulePermissions(): void
    {
        $this->cmd->task('permissions:modules');

        // Fix all file permissions (644 for files, 755 for directories)
        // Using + instead of \; to batch chmod calls for better performance
        $this->cmd->remote("find {$this->releasePath} -type f -exec chmod 644 {} + 2>/dev/null || true");
        $this->cmd->remote("find {$this->releasePath} -type d -exec chmod 755 {} + 2>/dev/null || true");

        // Fix vendor bin permissions (executables need 755)
        $this->cmd->remote("chmod -R 755 {$this->releasePath}/vendor/bin 2>/dev/null || true");

        $this->cmd->success('Module permissions fixed');
    }

    /**
     * Run database migrations
     */
    private function runMigrations(): void
    {
        $this->cmd->task('artisan:migrate');
        $this->cmd->info('Running database migrations...');

        $this->cmd->artisanMigrate($this->releasePath, force: true);

        $this->cmd->success('Migrations completed');
    }

    /**
     * Link .dep directory to release
     */
    private function linkDepDirectory(): void
    {
        $deployPath = $this->config->deployPath;

        $this->cmd->remote("ln -nfs {$deployPath}/.dep {$this->releasePath}/.dep");
    }

    /**
     * Symlink current to new release
     */
    private function symlinkRelease(): void
    {
        $this->cmd->task('release:symlink');

        $currentPath = $this->deployment->getCurrentPath();

        $this->cmd->remote("ln -nfs {$this->releasePath} {$currentPath}");

        $this->deployment->writeLatestRelease($this->releaseName);

        $this->cmd->success('Release symlinked as current');
    }

    /**
     * Cleanup old releases (keep configured number)
     */
    private function cleanupOldReleases(): void
    {
        $this->cmd->task('cleanup:releases');

        $keepReleases = $this->config->keepReleases ?? 3;
        $deployPath = $this->config->deployPath;

        $this->cmd->info("Cleaning up old releases (keeping {$keepReleases})...");

        // List releases sorted by time, skip the most recent ones, remove the rest
        $this->cmd->remote(
            "cd {$deployPath}/releases && ls -t | tail -n +".($keepReleases + 1).' | xargs -r rm -rf'
        );

        $remaining = trim($this->cmd->remote("ls -1 {$deployPath}/releases | wc -l"));

        $this->cmd->success("Cleanup complete. {$remaining} releases remain");
    }

    /**
     * Log deployment success
     */
    private function logDeploymentSuccess(): void
    {
        $deployPath = $this->config->deployPath;
        $logFile = "{$deployPath}/.dep/deploy.log";

        $timestamp = date('Y-m-d H:i:s');
        $user = $this->deployment->getUser();
        $logEntry = "[{$timestamp}] {$user} deployed release {$this->releaseName} to {$this->config->environment->value}";

        $this->cmd->remote("echo '{$logEntry}' >> {$logFile}");

        // Also log release info
        $releaseInfo = new ReleaseInfo(
            name: $this->releaseName,
            createdAt: new \DateTimeImmutable,
            user: $user,
            branch: $this->config->branch
        );

        $this->deployment->logRelease($releaseInfo);
    }

    /**
     * Run post-deployment hooks if they exist
     */
    private function runPostDeploymentHooks(): void
    {
        $deployPath = $this->config->deployPath;
        $hookScript = "{$deployPath}/.dep/post-deploy.sh";

        if ($this->cmd->fileExists($hookScript)) {
            $this->cmd->task('hooks:post-deploy');
            $this->cmd->info('Running post-deployment hooks...');

            $this->cmd->remote("bash {$hookScript}");

            $this->cmd->success('Post-deployment hooks completed');
        }
    }

    /**
     * Unlock deployment
     */
    private function unlockDeployment(): void
    {
        $this->deployment->unlock();
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
}
