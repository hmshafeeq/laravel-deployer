<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Concerns\ManagesLocking;
use Shaf\LaravelDeployer\Constants\Paths;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\DeploymentReceipt;
use Shaf\LaravelDeployer\Data\ReleaseInfo;
use Shaf\LaravelDeployer\Data\SyncDiff;
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
    use ManagesLocking;

    private string $releaseName;

    private string $releasePath;

    private ?SyncDiff $syncDiff = null;

    private float $duration = 0;

    private ?HooksService $hooks = null;

    private StepTimer $stepTimer;

    private ?string $gitCommitHash = null;

    private ?string $gitCommitMessage = null;

    private ?string $gitAuthor = null;

    private int $migrationsRun = 0;

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

        // Initialize hooks service if hooks are configured
        if (! empty($config->hooks)) {
            $this->hooks = new HooksService($cmd, $config);
            $this->hooks->loadHooks($config->hooks);
        }
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

            // 4. Build assets locally (if not local deployment)
            if (! $this->config->isLocal) {
                $this->runHook('before:build');
                $this->stepTimer->start('assets:build');
                $this->buildAssets();
                $this->stepTimer->end('assets:build');
                $this->runHook('after:build');
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
            $this->runHook('before:sync');
            $this->stepTimer->start('files:sync');
            $this->syncFilesWithProgress();
            $this->stepTimer->end('files:sync');
            $this->runHook('after:sync');

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

            // 11. Fix module permissions
            $this->stepTimer->start('permissions:fix');
            $this->fixModulePermissions();

            // 12. Set writable permissions (must run after fixModulePermissions)
            $this->setWritablePermissions();
            $this->stepTimer->end('permissions:fix');

            // 13. Run database migrations
            $this->runHook('before:migrate');
            $this->stepTimer->start('artisan:migrate');
            $this->runMigrations();
            $this->stepTimer->end('artisan:migrate');
            $this->runHook('after:migrate');

            // 14. Link .dep directory
            $this->linkDepDirectory();

            // 15. Symlink current release
            $this->runHook('before:symlink');
            $this->stepTimer->start('release:symlink');
            $this->symlinkRelease();
            $this->stepTimer->end('release:symlink');
            $this->runHook('after:symlink');

            // 16. Verify deployment health
            if ($this->healthCheck !== null) {
                $this->stepTimer->start('health:verify');
                $this->verifyDeploymentHealth();
                $this->stepTimer->end('health:verify');
            }

            // 17. Cleanup old releases
            $this->stepTimer->start('cleanup:releases');
            $this->cleanupOldReleases();
            $this->stepTimer->end('cleanup:releases');

            // 18. Log deployment success
            $this->logDeploymentSuccess();

            // 19. Run post-deployment hooks (legacy)
            $this->stepTimer->start('hooks:post-deploy');
            $this->runPostDeploymentHooks();
            $this->stepTimer->end('hooks:post-deploy');

            // Calculate total deployment time
            $this->duration = microtime(true) - $startTime;

            // 20. Generate deployment receipt
            $this->generateReceipt(success: true);

            // Run after:deploy hooks
            $this->runHook('after:deploy');

        } catch (\Exception $e) {
            // Run on:failure hooks
            $this->runHook('on:failure');
            throw $e;
        } finally {
            // Always unlock deployment, even if there's an error
            $this->unlockDeployment();
        }
    }

    /**
     * Run a deployment hook if configured
     */
    private function runHook(string $hookPoint): void
    {
        $this->hooks?->run($hookPoint);
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
            if ($this->config->assetsFailOnError) {
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

        // Pass sync diff and output for progress bar
        $this->rsync->setSyncDiff($this->syncDiff);
        $this->rsync->setOutput($this->cmd->getOutput());

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
     * Fix permissions on shared log files
     *
     * Log files created by the web server (www-data) may have restrictive permissions
     * that prevent proper logging during deployment or by new worker processes.
     */
    private function fixSharedLogPermissions(): void
    {
        $sharedPath = $this->deployment->getSharedPath();
        $logsPath = "{$sharedPath}/storage/logs";
        $escapedLogsPath = CommandService::escapePath($logsPath);

        // Fix permissions on existing log files (if any exist)
        // Use find with -exec to handle the case where no .log files exist
        // Set group to www-data and permissions to 664 so both deploy user and web server can write
        $this->cmd->remote(
            "find {$escapedLogsPath} -name '*.log' -type f -exec chgrp www-data {} + -exec chmod 664 {} + 2>/dev/null || true"
        );
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

        // Check for composer.lock file and warn if missing
        $this->checkComposerLock();

        $composerOptions = $this->config->composerOptions ?? '--prefer-dist --no-interaction --no-scripts --no-plugins --no-dev --optimize-autoloader';
        $escapedPath = CommandService::escapePath($this->releasePath);

        // Ensure bootstrap/cache exists before composer runs (required for package:discover)
        $bootstrapCache = CommandService::escapePath("{$this->releasePath}/bootstrap/cache");
        $this->cmd->remote("mkdir -p {$bootstrapCache} && chmod 775 {$bootstrapCache}");

        // If GitHub token is provided, write auth.json file (avoids token in command line logs)
        $authJsonPath = "{$this->releasePath}/auth.json";
        if ($this->config->githubToken) {
            $this->createComposerAuthFile($authJsonPath);
        }

        try {
            $composerCommand = "cd {$escapedPath} && composer install {$composerOptions}";
            // Use remoteWithOutput so composer output is visible at -v level
            $this->cmd->remoteWithOutput($composerCommand);
        } finally {
            // Clean up auth.json to avoid leaving credentials on server
            if ($this->config->githubToken) {
                $this->cmd->remote('rm -f '.CommandService::escapePath($authJsonPath));
            }
        }

        $this->cmd->success('Composer dependencies installed');
    }

    /**
     * Create auth.json file for Composer authentication.
     * This avoids exposing tokens in command line logs.
     */
    private function createComposerAuthFile(string $authJsonPath): void
    {
        $authConfig = json_encode([
            'github-oauth' => [
                'github.com' => $this->config->githubToken,
            ],
        ], JSON_UNESCAPED_SLASHES);

        $escapedContent = escapeshellarg($authConfig);
        $escapedPath = CommandService::escapePath($authJsonPath);

        // Write auth.json with secure permissions
        $this->cmd->remote("echo {$escapedContent} > {$escapedPath} && chmod 600 {$escapedPath}");
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
     * Run database migrations with optional maintenance mode and backup
     */
    private function runMigrations(): void
    {
        $this->cmd->task('artisan:migrate');
        $this->cmd->info('Running database migrations...');

        $maintenanceEnabled = false;

        try {
            // Optional: Backup database before migrations
            if ($this->config->backupBeforeMigrate) {
                $this->runPreMigrationBackup();
            }

            // Optional: Enable maintenance mode
            if ($this->config->maintenanceMode) {
                $this->enableMaintenanceMode();
                $maintenanceEnabled = true;
            }

            // Run migrations
            $result = $this->cmd->artisanMigrate($this->releasePath, force: true);
            $this->migrationsRun = $result['count'];

            $this->cmd->success('Migrations completed');

        } finally {
            // Always disable maintenance mode if we enabled it
            if ($maintenanceEnabled) {
                $this->disableMaintenanceMode();
            }
        }
    }

    /**
     * Run database backup before migrations
     */
    private function runPreMigrationBackup(): void
    {
        $this->cmd->info('Creating pre-migration database backup...');

        try {
            // Use the backup command from the release
            $this->cmd->artisan('backup:run --only-db', $this->releasePath);
            $this->cmd->success('Pre-migration backup created');
        } catch (\Exception $e) {
            // Backup failure is non-critical - warn but continue
            $this->cmd->warning("Pre-migration backup failed: {$e->getMessage()}");
            $this->cmd->warning('Continuing with migrations...');
        }
    }

    /**
     * Enable maintenance mode on the current release
     */
    private function enableMaintenanceMode(): void
    {
        $this->cmd->info('Enabling maintenance mode...');

        $currentPath = "{$this->config->deployPath}/current";
        $secretOption = $this->config->maintenanceSecret
            ? " --secret={$this->config->maintenanceSecret}"
            : '';

        try {
            $this->cmd->artisan("down{$secretOption}", $currentPath);
            $this->cmd->success('Maintenance mode enabled');
        } catch (\Exception $e) {
            // If current doesn't exist yet (first deploy), skip
            $this->cmd->warning('Could not enable maintenance mode (may be first deploy)');
        }
    }

    /**
     * Disable maintenance mode
     */
    private function disableMaintenanceMode(): void
    {
        $this->cmd->info('Disabling maintenance mode...');

        // Use the new release path since it's now current
        try {
            $this->cmd->artisan('up', $this->releasePath);
            $this->cmd->success('Maintenance mode disabled');
        } catch (\Exception $e) {
            $this->cmd->warning("Could not disable maintenance mode: {$e->getMessage()}");
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

        // Batch symlink creation and latest release file write
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
        $postDeployCommands = $this->config->postDeployCommands;

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

        // Build the URL if health check URL is configured
        $url = null;
        if ($this->config->healthCheckUrl) {
            $scheme = $this->config->environment->isProduction() ? 'https' : 'http';
            $url = "{$scheme}://{$this->config->hostname}";
        }

        $summary->showSuccess(
            releaseName: $this->releaseName,
            duration: $this->duration,
            syncDiff: $this->syncDiff,
            migrationsRun: $this->migrationsRun,
            url: $url,
            stepTimings: $this->stepTimer->getTimings(),
            gitInfo: $this->getGitInfo()
        );
    }

    /**
     * Get the step timer for external access
     */
    public function getStepTimer(): StepTimer
    {
        return $this->stepTimer;
    }

    /**
     * Get git info for summary/notifications
     *
     * @return array{branch: string, commit: ?string, message: ?string, author: ?string}
     */
    public function getGitInfo(): array
    {
        return [
            'branch' => $this->config->branch,
            'commit' => $this->gitCommitHash,
            'message' => $this->gitCommitMessage,
            'author' => $this->gitAuthor,
        ];
    }

    /**
     * Capture git information from the current repository
     */
    private function captureGitInfo(): void
    {
        // Get short commit hash
        $this->gitCommitHash = trim((string) shell_exec('git rev-parse --short HEAD 2>/dev/null'));

        // Get commit message (first line only)
        $this->gitCommitMessage = trim((string) shell_exec('git log -1 --format=%s 2>/dev/null'));

        // Get author name
        $this->gitAuthor = trim((string) shell_exec('git log -1 --format=%an 2>/dev/null'));
    }

    /**
     * Display git information at start of deployment
     */
    private function showGitInfo(): void
    {
        if (! $this->gitCommitHash) {
            return;
        }

        $branch = $this->config->branch;
        $commit = $this->gitCommitHash;
        $author = $this->gitAuthor ?: 'Unknown';

        $this->cmd->info("📦 Deploying: {$branch} @ {$commit} ({$author})");

        if ($this->gitCommitMessage) {
            // Truncate long commit messages
            $message = mb_strlen($this->gitCommitMessage) > 60
                ? mb_substr($this->gitCommitMessage, 0, 57).'...'
                : $this->gitCommitMessage;
            $this->cmd->info("   \"{$message}\"");
        }
    }

    /**
     * Check if composer.lock exists and warn if missing
     */
    private function checkComposerLock(): void
    {
        $lockFile = "{$this->releasePath}/composer.lock";

        if (! $this->cmd->fileExists($lockFile)) {
            $this->cmd->warning('⚠️  Warning: composer.lock not found in release!');
            $this->cmd->warning('   Dependencies resolved fresh (slower). Consider tracking composer.lock.');
        }
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
            syncDiff: $this->syncDiff,
            postDeployCommands: $this->config->postDeployCommands,
            success: $success,
            errorMessage: $errorMessage
        );

        $this->receiptService->save($receipt);
        $this->cmd->success('Deployment receipt saved');
    }
}
