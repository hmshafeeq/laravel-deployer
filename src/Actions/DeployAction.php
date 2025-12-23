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

            // 9. Install composer dependencies
            $this->installComposerDependencies();

            // 10. Fix module permissions
            $this->fixModulePermissions();

            // 11. Set writable permissions (must run after fixModulePermissions)
            $this->setWritablePermissions();

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

        // Batch all mkdir commands into a single SSH call
        $escapedDirs = array_map([CommandService::class, 'escapePath'], $directories);
        $sharedEnvPath = CommandService::escapePath("{$deployPath}/".Paths::SHARED_DIR.'/.env');

        $this->cmd->runBatch([
            'mkdir -p '.implode(' ', $escapedDirs),
            "touch {$sharedEnvPath}",
        ]);

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
        $escapedPath = CommandService::escapePath($this->releasePath);

        $this->cmd->remote("mkdir -p {$escapedPath}");

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
            $failOnError = config('laravel-deployer.assets.fail_on_error', true);

            if ($failOnError) {
                throw new \RuntimeException('Asset build failed: '.$e->getMessage(), 0, $e);
            }

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

        // Prepare all paths
        $escapedReleaseStorage = CommandService::escapePath("{$this->releasePath}/storage");
        $escapedSharedStorage = CommandService::escapePath("{$sharedPath}/storage");
        $escapedSharedEnv = CommandService::escapePath("{$sharedPath}/.env");
        $escapedReleaseEnv = CommandService::escapePath("{$this->releasePath}/.env");

        // Batch all shared link operations into a single SSH call
        $this->cmd->runBatch([
            "rm -rf {$escapedReleaseStorage}",
            "ln -nfs {$escapedSharedStorage} {$escapedReleaseStorage}",
            "ln -nfs {$escapedSharedEnv} {$escapedReleaseEnv}",
        ]);

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

        $commands = [];

        foreach ($writableDirs as $dir) {
            $escapedDir = CommandService::escapePath($dir);

            // Skip symlinks - shared directories (like storage) are already properly configured
            // and may contain files owned by www-data that the deploy user can't chmod
            if ($this->cmd->symlinkExists($dir)) {
                $this->cmd->info("  Skipping {$dir} (symlink to shared)");

                continue;
            }

            // Create directory if it doesn't exist (e.g., bootstrap/cache is often gitignored)
            // Set group to www-data and permissions to 775 so web server can write
            $commands[] = "mkdir -p {$escapedDir}";
            $commands[] = "chgrp -R www-data {$escapedDir}";
            $commands[] = "chmod -R 775 {$escapedDir}";
        }

        if (! empty($commands)) {
            $this->cmd->runBatch($commands);
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
        $escapedPath = CommandService::escapePath($this->releasePath);

        // Build composer command with GitHub token if provided
        $composerCommand = "cd {$escapedPath}";

        if ($this->config->githubToken) {
            // Set COMPOSER_AUTH environment variable for GitHub authentication
            $composerAuth = json_encode([
                'github-oauth' => [
                    'github.com' => $this->config->githubToken,
                ],
            ]);
            $composerCommand .= " && COMPOSER_AUTH='{$composerAuth}'";
        }

        $composerCommand .= " composer install {$composerOptions}";

        $this->cmd->remote($composerCommand);

        $this->cmd->success('Composer dependencies installed');
    }

    /**
     * Fix module permissions
     */
    private function fixModulePermissions(): void
    {
        $this->cmd->task('permissions:modules');

        $escapedPath = CommandService::escapePath($this->releasePath);
        $escapedVendorBin = CommandService::escapePath("{$this->releasePath}/vendor/bin");

        // Batch all permission fixes into a single SSH call
        // - 644 for files, 755 for directories
        // - vendor/bin gets 755 if it exists (using shell conditional)
        $this->cmd->runBatch([
            "find {$escapedPath} -type f -exec chmod 644 {} +",
            "find {$escapedPath} -type d -exec chmod 755 {} +",
            "[ -d {$escapedVendorBin} ] && chmod -R 755 {$escapedVendorBin} || true",
        ]);

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

        // Batch symlink creation and latest release file write
        $this->cmd->runBatch([
            "ln -nfs {$escapedReleasePath} {$escapedCurrentPath}",
            "echo {$this->releaseName} > {$escapedLatestRelease}",
        ]);

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
        $escapedReleasesPath = CommandService::escapePath("{$deployPath}/releases");

        $this->cmd->info("Cleaning up old releases (keeping {$keepReleases})...");

        try {
            // Batch cleanup and count into a single SSH call
            // Use || true to prevent failure if some files can't be deleted
            $output = $this->cmd->remote(
                "cd {$escapedReleasesPath} && ls -t | tail -n +".($keepReleases + 1).' | xargs -r rm -rf 2>/dev/null || true; '.
                "ls -1 {$escapedReleasesPath} | wc -l"
            );

            $remaining = trim($output);
            $this->cmd->success("Cleanup complete. {$remaining} releases remain");
        } catch (\Exception $e) {
            // Cleanup is non-critical - warn but don't fail the deployment
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

        // Log release info (this is a local PHP operation via DeploymentService)
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
        $this->cmd->task('hooks:post-deploy');

        // Run configured post-deployment artisan commands
        $postDeployCommands = config('laravel-deployer.post_deploy_commands', []);

        if (! empty($postDeployCommands)) {
            $this->cmd->info('Running post-deployment commands...');

            foreach ($postDeployCommands as $command) {
                $this->cmd->info("  → artisan {$command}");
                $this->cmd->artisan($command, $this->releasePath);
            }

            $this->cmd->success('Post-deployment commands completed');
        }

        // Run post-deploy shell script if it exists
        $deployPath = $this->config->deployPath;
        $hookScript = "{$deployPath}/.dep/post-deploy.sh";
        $escapedHookScript = CommandService::escapePath($hookScript);

        if ($this->cmd->fileExists($hookScript)) {
            $this->cmd->info('Running post-deployment script...');

            $this->cmd->remote("bash {$escapedHookScript}");

            $this->cmd->success('Post-deployment script completed');
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
