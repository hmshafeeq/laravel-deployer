<?php

namespace Shaf\LaravelDeployer\Actions;

use Illuminate\Support\Str;
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

            // 4. Build assets locally (if not local deployment and not skipping build folder)
            $skipAssetBuild = in_array('public/build/', $this->config->rsyncExcludes, true);
            if (! $this->config->isLocal && ! $skipAssetBuild) {
                $this->runHook('before:build');
                $this->stepTimer->start('assets:build');
                $this->buildAssets();
                $this->stepTimer->end('assets:build');
                $this->runHook('after:build');
            } elseif ($skipAssetBuild) {
                $this->cmd->info('Skipping asset build (public/build excluded)');
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
            $this->syncFilesWithProgress();
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
            // Note: vendor is installed fresh (excluded from previous release copy)
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
            $this->runHook('before:migrate');
            $this->stepTimer->start('artisan:migrate');
            $this->runMigrations();
            $this->stepTimer->end('artisan:migrate');
            $this->runHook('after:migrate');

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
     * Cleanup SSH control master sockets
     */
    private function cleanupSshSockets(): void
    {
        if ($this->config->isLocal) {
            return; // No SSH sockets for local deployments
        }

        $this->cmd->debug('Cleaning up SSH control sockets...');

        // Close SSH control master connection gracefully
        $host = $this->config->hostname;
        $user = $this->config->remoteUser;
        $port = $this->config->port ?? 22;
        shell_exec("ssh -O exit -o ControlPath=/tmp/deployer-{$user}@{$host}:{$port} {$user}@{$host} 2>/dev/null || true");
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

        $commands = [
            'mkdir -p '.implode(' ', $escapedDirs),
            "touch {$sharedEnvPath}",
        ];

        // Enforce setgid on shared directories for group inheritance
        // This ensures files created by www-data inherit the correct group
        // Only runs on shared/ dir (not entire deploy path) for performance
        if ($this->config->enforceSetgid) {
            $escapedSharedPath = CommandService::escapePath("{$deployPath}/".Paths::SHARED_DIR);
            $commands[] = "find {$escapedSharedPath} -type d -exec chmod g+s {} \\; 2>/dev/null || true";
        }

        $this->cmd->runBatch($commands);

        $this->cmd->success('Deployment structure ready');
    }

    /**
     * Generate release name and create release directory
     * Note: Directory is created in generateReleaseName() for performance
     */
    private function createRelease(): void
    {
        $this->cmd->task('release:create');

        // generateReleaseName() also creates the release directory in the same SSH call
        $this->releaseName = $this->deployment->generateReleaseName();
        $this->deployment->setCurrentReleaseName($this->releaseName);

        $this->releasePath = $this->deployment->getReleasePath($this->releaseName);

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

        // Copy previous release before rsync
        // This speeds up rsync - it only needs to update changed files
        $this->copyPreviousRelease();

        // Note: If early diff was empty but we copied from previous release,
        // rsync may still transfer files due to seeding from previous release
        if ($this->syncDiff !== null && $this->syncDiff->isEmpty()) {
            $this->cmd->debug('Note: Diff was calculated against current release; seeded copy may show different transfer activity');
        }

        // Pass sync diff and output for progress bar
        $this->rsync->setSyncDiff($this->syncDiff);
        $this->rsync->setOutput($this->cmd->getOutput());

        // For local deployments, just use the path; RsyncService handles the rest
        $this->rsync->sync($this->releasePath);

        // Capture actual sync stats from rsync (not theoretical diff)
        $this->syncStats = SyncStats::fromRsync($this->rsync, $this->syncDiff);

        $this->cmd->success('Files synced successfully');
    }

    /**
     * Verify that critical assets exist on the server after file sync.
     * This is an optional verification step that warns (but doesn't fail)
     * if configured assets are missing from the deployed release.
     */
    private function verifyAssets(): void
    {
        $assetsToVerify = $this->config->assetsVerify;

        if (empty($assetsToVerify)) {
            return;
        }

        $this->cmd->task('assets:verify');
        $this->cmd->info('Verifying critical assets...');

        $missingAssets = [];

        foreach ($assetsToVerify as $assetPath) {
            $fullPath = "{$this->releasePath}/{$assetPath}";
            $escapedPath = CommandService::escapePath($fullPath);

            // Determine if path is a directory (ends with /) or file
            $isDirectory = str_ends_with($assetPath, '/');

            if ($isDirectory) {
                // Check if directory exists using test -d
                $exists = $this->cmd->test("[ -d {$escapedPath} ]");
            } else {
                // Check if file exists using test -f
                $exists = $this->cmd->test("[ -f {$escapedPath} ]");
            }

            if (! $exists) {
                $missingAssets[] = $assetPath;
            }
        }

        if (! empty($missingAssets)) {
            $this->cmd->warning('⚠️  Some assets were not found on server:');
            foreach ($missingAssets as $asset) {
                $this->cmd->warning("   - {$asset}");
            }
            $this->addWarning('assets', 'Missing assets: '.implode(', ', $missingAssets));
            $this->cmd->newLine();
        } else {
            $count = count($assetsToVerify);
            $this->cmd->success("All {$count} critical asset(s) verified");
        }
    }

    /**
     * Copy previous release to new release directory.
     * This seeds the release with existing files so rsync only transfers changes.
     * Uses regular copy (not hardlinks) for clean release isolation.
     *
     * When copyVendor is enabled (default), vendor/ is included in the copy.
     * This saves ~40s on composer install as it only validates instead of fresh install.
     */
    private function copyPreviousRelease(): void
    {
        $previousRelease = $this->deployment->getCurrentRelease();
        if (! $previousRelease) {
            $this->cmd->debug('No previous release found, skipping copy');

            return;
        }

        $previousReleasePath = $this->deployment->getReleasePath($previousRelease);

        // Check if previous release exists
        if (! $this->cmd->directoryExists($previousReleasePath)) {
            $this->cmd->debug('Previous release directory does not exist, skipping copy');

            return;
        }

        $this->cmd->info('Copying previous release...');

        $escapedPrevious = CommandService::escapePath($previousReleasePath);
        $escapedNew = CommandService::escapePath($this->releasePath);

        if ($this->config->copyVendor) {
            // Copy entire previous release including vendor/ (saves ~40s on composer install)
            // Exclude only node_modules (not needed on server) and bootstrap/cache (regenerated)
            // Note: Using /. syntax to copy CONTENTS, not the directory itself (prevents nesting)
            $this->cmd->remote(
                "cp -rp {$escapedPrevious}/. {$escapedNew}/ && ".
                "rm -rf {$escapedNew}/node_modules && ".
                "rm -rf {$escapedNew}/bootstrap/cache && ".
                "mkdir -p {$escapedNew}/bootstrap/cache"
            );
            $this->cmd->success('Previous release copied (with vendor)');
        } else {
            // Original behavior: exclude vendor/ for fresh composer install
            // Note: Using /. syntax to copy CONTENTS, not the directory itself (prevents nesting)
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
        // Set group to configured web group and permissions for write access
        $webGroup = $this->config->webGroup;
        $fileMode = $this->config->fileMode;
        $this->cmd->remote(
            "find {$escapedLogsPath} -name '*.log' -type f -exec chgrp {$webGroup} {} + -exec chmod {$fileMode} {} + 2>/dev/null || true"
        );
    }

    /**
     * Fix all permissions in a single SSH batch operation.
     * Combines module permissions and writable directory setup.
     * Can be skipped via skipPermissionFix config when server umask is correctly configured.
     */
    private function fixPermissions(): void
    {
        $this->cmd->task('permissions:fix');

        // Skip if configured (useful when server umask is correctly set to 022)
        if ($this->config->skipPermissionFix) {
            $this->cmd->info('Skipping permission fix (skipPermissionFix is enabled)');

            return;
        }

        $escapedPath = CommandService::escapePath($this->releasePath);
        $escapedVendorBin = CommandService::escapePath("{$this->releasePath}/vendor/bin");
        $escapedBootstrapCache = CommandService::escapePath("{$this->releasePath}/bootstrap/cache");

        $fileMode = $this->config->fileMode;
        $dirMode = $this->config->directoryMode;
        $webGroup = $this->config->webGroup;

        // Batch all permission operations into a single SSH call:
        // 1. Set configured file mode for all files
        // 2. Set configured directory mode for all directories
        // 3. Make vendor/bin executable if it exists
        // 4. Ensure bootstrap/cache exists with proper group and permissions
        $this->cmd->runBatch([
            "find {$escapedPath} -type f -exec chmod {$fileMode} {} +",
            "find {$escapedPath} -type d -exec chmod {$dirMode} {} +",
            "[ -d {$escapedVendorBin} ] && chmod -R 755 {$escapedVendorBin} || true",
            "mkdir -p {$escapedBootstrapCache} && chgrp -R {$webGroup} {$escapedBootstrapCache}",
        ]);

        $this->cmd->success('Permissions fixed');
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

        // Clear bootstrap/cache before composer runs.
        // When copying from previous release, cached files may contain
        // stale absolute paths to old releases. This must be cleared before
        // artisan package:discover runs to prevent path resolution errors.
        $bootstrapCache = CommandService::escapePath("{$this->releasePath}/bootstrap/cache");
        $this->cmd->remote("rm -rf {$bootstrapCache} && mkdir -p {$bootstrapCache} && chmod 775 {$bootstrapCache}");

        // If GitHub token is provided, write auth.json file (avoids token in command line logs)
        $authJsonPath = "{$this->releasePath}/auth.json";
        if ($this->config->githubToken) {
            $this->createComposerAuthFile($authJsonPath);
        }

        try {
            $composerCommand = "cd {$escapedPath} && composer install {$composerOptions}";
            // Use remoteWithOutput so composer output is visible at -v level
            $output = $this->cmd->remoteWithOutput($composerCommand);

            // Parse Composer output for warnings
            $this->parseComposerWarnings($output);
        } finally {
            // Clean up auth.json to avoid leaving credentials on server
            if ($this->config->githubToken) {
                $this->cmd->remote('rm -f '.CommandService::escapePath($authJsonPath));
            }
        }

        $this->cmd->success('Composer dependencies installed');
    }

    /**
     * Parse Composer output for known warnings
     */
    private function parseComposerWarnings(string $output): void
    {
        // Check for lock file out of sync warning
        if (str_contains($output, 'lock file is not up to date')) {
            $this->addWarning('composer', 'Lock file not up to date with composer.json');
        }

        // Check for abandoned packages
        if (preg_match('/Package\s+\S+\s+is abandoned/i', $output)) {
            $this->addWarning('composer', 'One or more packages are abandoned');
        }
    }

    /**
     * Add a deployment warning
     */
    private function addWarning(string $category, string $message): void
    {
        $this->warnings[] = [
            'category' => $category,
            'message' => $message,
        ];
    }

    /**
     * Get all deployment warnings
     *
     * @return array<array{category: string, message: string}>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
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
     * Run optimization commands before symlinking release.
     * These commands are critical - deployment aborts if they fail.
     */
    private function runBeforeSymlinkCommands(): void
    {
        $beforeSymlink = $this->config->beforeSymlink ?? [];

        if (empty($beforeSymlink)) {
            return;
        }

        // Validate configuration and warn about redundant commands
        $this->validateBeforeSymlinkCommands();

        $this->cmd->task('optimize:release');
        $this->cmd->info('Running pre-symlink optimization commands...');

        $phpBinary = $this->config->phpBinary;
        $artisanPath = "{$this->releasePath}/artisan";

        // Run commands one at a time to catch errors properly
        foreach ($beforeSymlink as $command) {
            if ($this->isArtisanShortcut($command)) {
                $fullCommand = "cd {$this->releasePath} && {$phpBinary} {$artisanPath} {$command}";
                $displayCmd = "artisan {$command}";
            } else {
                $fullCommand = "cd {$this->releasePath} && {$command}";
                $displayCmd = $command;
            }

            $this->cmd->info("  → {$displayCmd}");

            try {
                $output = $this->cmd->remoteWithOutput($fullCommand);

                // Check for Laravel ERROR markers even if exit code is 0
                if (str_contains($output, '  ERROR  ') || str_contains($output, 'Failed to clear cache')) {
                    throw new \RuntimeException("Command failed: {$displayCmd}");
                }
            } catch (\Exception $e) {
                // Try to fix permissions and retry once
                $this->cmd->warning('  ⚠ Command failed, attempting to fix permissions and retry...');

                try {
                    $this->fixBootstrapCachePermissions();
                    $output = $this->cmd->remoteWithOutput($fullCommand);

                    // Check again after retry
                    if (str_contains($output, '  ERROR  ') || str_contains($output, 'Failed to clear cache')) {
                        throw new \RuntimeException("Command failed after retry: {$displayCmd}\n{$output}");
                    }

                    $this->cmd->info('  ✓ Retry successful');
                } catch (\Exception $retryException) {
                    $this->cmd->error("  ✗ Command failed after retry: {$displayCmd}");
                    throw new \RuntimeException(
                        "Pre-symlink optimization failed: {$displayCmd}\n".
                        "Error: {$retryException->getMessage()}\n".
                        'Deployment aborted to prevent issues.',
                        0,
                        $retryException
                    );
                }
            }
        }

        $this->cmd->success('Pre-symlink optimization completed');
    }

    /**
     * Fix permissions on bootstrap/cache and shared storage
     * This is called when cache:clear fails due to permission issues
     */
    private function fixBootstrapCachePermissions(): void
    {
        $bootstrapCache = CommandService::escapePath("{$this->releasePath}/bootstrap/cache");
        $sharedStorage = CommandService::escapePath("{$this->config->deployPath}/shared/storage");
        $webGroup = $this->config->webGroup;
        $deployUser = $this->config->remoteUser;

        // Fix permissions on both locations:
        // 1. Change group to www-data
        // 2. Set group write permissions (2775 for dirs, 664 for files)
        // 3. Change ownership to deploy user so they can delete files
        // 4. Set setgid bit on directories so new files inherit group
        $this->cmd->remote(
            "sudo chgrp -R {$webGroup} {$bootstrapCache} {$sharedStorage} && ".
            "sudo chmod -R 2775 {$bootstrapCache} {$sharedStorage} && ".
            "sudo find {$bootstrapCache} {$sharedStorage} -type f -exec chmod 664 {} + && ".
            "sudo find {$bootstrapCache} {$sharedStorage} -type d -exec chmod 2775 {} + && ".
            "sudo chown -R {$deployUser}:{$webGroup} {$bootstrapCache} {$sharedStorage}"
        );
    }

    /**
     * Validate beforeSymlink commands and warn about redundant configurations
     */
    private function validateBeforeSymlinkCommands(): void
    {
        $beforeSymlink = $this->config->beforeSymlink ?? [];

        if (empty($beforeSymlink)) {
            return;
        }

        $hasOptimize = false;
        $hasCacheClear = false;
        $hasIndividualClears = false;
        $hasIndividualCaches = false;

        foreach ($beforeSymlink as $cmd) {
            // Check for 'artisan optimize' but NOT 'optimize:clear'
            if (str_contains($cmd, 'artisan optimize') && ! str_contains($cmd, 'optimize:clear')) {
                $hasOptimize = true;
            }
            if (str_contains($cmd, 'cache:clear')) {
                $hasCacheClear = true;
            }
            if (str_contains($cmd, 'config:clear') ||
                str_contains($cmd, 'route:clear') ||
                str_contains($cmd, 'view:clear') ||
                str_contains($cmd, 'event:clear')) {
                $hasIndividualClears = true;
            }
            if (str_contains($cmd, 'config:cache') ||
                str_contains($cmd, 'route:cache') ||
                str_contains($cmd, 'view:cache') ||
                str_contains($cmd, 'event:cache')) {
                $hasIndividualCaches = true;
            }
        }

        // Show warnings for redundant configurations
        if ($hasOptimize) {
            $this->cmd->warning('⚠️  Redundant: `artisan optimize` detected in beforeSymlink');
            $this->cmd->warning('   Optimization runs automatically AFTER symlink with fresh OPcache');
            $this->cmd->warning('   Recommendation: Remove `optimize` from beforeSymlink');
            $this->addWarning('config', 'Redundant: artisan optimize in beforeSymlink (runs automatically after symlink)');
            $this->cmd->newLine();
        }

        if ($hasCacheClear && $hasIndividualClears) {
            $this->cmd->warning('⚠️  Redundant: Individual :clear commands with cache:clear');
            $this->cmd->warning('   `cache:clear` already clears config, route, view, and event caches');
            $this->cmd->warning('   Recommendation: Remove individual :clear commands');
            $this->addWarning('config', 'Redundant: Individual :clear commands with cache:clear');
            $this->cmd->newLine();
        }

        if ($hasIndividualCaches && ! $hasOptimize) {
            $this->cmd->warning('⚠️  Suboptimal: Individual :cache commands in beforeSymlink');
            $this->cmd->warning('   Caching before symlink uses stale OPcache and may cause view errors');
            $this->cmd->warning('   Recommendation: Remove :cache commands (they run after symlink automatically)');
            $this->addWarning('config', 'Suboptimal: Individual :cache commands in beforeSymlink (may cause view errors)');
            $this->cmd->newLine();
        }
    }

    /**
     * Validate postDeploy commands and warn about redundant configurations.
     * OptimizeAction runs `artisan optimize` after service restart, so cache commands are redundant.
     */
    private function validatePostDeployCommands(): void
    {
        $postDeployCommands = $this->config->postDeployCommands;

        if (empty($postDeployCommands)) {
            return;
        }

        // Commands that are redundant because OptimizeAction handles them
        $redundantCommands = [
            'cache:clear' => 'optimize clears and rebuilds caches',
            'view:clear' => 'optimize handles views',
            'config:clear' => 'optimize handles config',
            'config:cache' => 'optimize handles config',
            'route:clear' => 'optimize handles routes',
            'route:cache' => 'optimize handles routes',
            'event:clear' => 'optimize handles events',
            'event:cache' => 'optimize handles events',
            'optimize' => 'runs automatically after service restart',
        ];

        foreach ($postDeployCommands as $cmd) {
            foreach ($redundantCommands as $redundantCmd => $reason) {
                if (str_contains($cmd, $redundantCmd)) {
                    $this->cmd->warning("⚠️  Redundant: `{$redundantCmd}` detected in postDeploy");
                    $this->cmd->warning('   The OptimizeAction already clears and rebuilds caches after service restart');
                    $this->cmd->warning('   Recommendation: Remove cache-related commands from postDeploy');
                    $this->addWarning('config', "Redundant: {$redundantCmd} in postDeploy ({$reason})");
                    $this->cmd->newLine();
                    break; // Only warn once per command
                }
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
     * Create storage symlink in the release
     * Must run before symlinking the release, not after
     */
    private function createStorageLink(): void
    {
        $this->cmd->task('storage:link');
        $this->cmd->info('Creating storage symlink...');

        try {
            $this->cmd->artisanStorageLink($this->releasePath);
            $this->cmd->success('Storage symlink created');
        } catch (\Exception $e) {
            // Storage link failure is non-critical - warn but continue
            // The symlink may already exist from hardlink copy
            $this->cmd->warning("Storage link creation failed (may already exist): {$e->getMessage()}");
        }
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
     * Run post-deployment hooks if they exist.
     * Supports both artisan shortcuts (e.g., "config:cache") and full shell commands (e.g., "npm run build").
     * Batches all commands into a single SSH call for performance.
     */
    private function runPostDeploymentHooks(): void
    {
        $this->cmd->task('hooks:post-deploy');

        // Validate configuration and warn about redundant commands
        $this->validatePostDeployCommands();

        // Run configured post-deployment commands
        $postDeployCommands = $this->config->postDeployCommands;

        if (! empty($postDeployCommands)) {
            $this->cmd->info('Running post-deployment commands...');

            $phpBinary = $this->config->phpBinary;
            $artisanPath = "{$this->releasePath}/artisan";

            // Build commands - detect if already full command or artisan shortcut
            $batchedCommands = [];
            foreach ($postDeployCommands as $command) {
                if ($this->isArtisanShortcut($command)) {
                    // Artisan shortcut (e.g., "config:cache") - wrap with php artisan
                    $fullCommand = "{$phpBinary} {$artisanPath} {$command}";
                    $this->cmd->info("  → artisan {$command}");
                } else {
                    // Full command - run as-is (e.g., "php artisan migrate", "npm run build")
                    $fullCommand = "cd {$this->releasePath} && {$command}";
                    $this->cmd->info("  → {$command}");
                }
                $batchedCommands[] = $fullCommand;
            }

            // Use remoteWithOutput so errors are visible
            $this->cmd->remoteWithOutput(implode(' && ', $batchedCommands));

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

        // Validate git state
        $this->validateGitState();
    }

    /**
     * Validate git state and warn about potential issues
     */
    private function validateGitState(): void
    {
        // Check for uncommitted changes
        $status = trim((string) shell_exec('git status --porcelain 2>/dev/null'));
        if (! empty($status)) {
            $this->cmd->warning('⚠️  Warning: Deploying with uncommitted changes');
            $this->addWarning('git', 'Uncommitted changes detected');
        }

        // Verify branch matches config
        $currentBranch = trim((string) shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null'));
        if ($currentBranch && $currentBranch !== $this->config->branch) {
            $this->cmd->warning("⚠️  Warning: On branch '{$currentBranch}' but config expects '{$this->config->branch}'");
            $this->addWarning('git', "Branch mismatch: on {$currentBranch}, config expects {$this->config->branch}");
        }
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
            $message = Str::limit($this->gitCommitMessage, 60);
            $this->cmd->info("   \"{$message}\"");
        }
    }

    /**
     * Check if composer.lock exists locally and warn if missing.
     * Note: composer.lock is typically excluded from rsync, so we check locally.
     */
    private function checkComposerLock(): void
    {
        $localLockFile = base_path('composer.lock');

        if (! file_exists($localLockFile)) {
            $this->cmd->warning('⚠️  Warning: composer.lock not found locally!');
            $this->cmd->warning('   Run "composer install" to generate lock file for reproducible builds.');
            $this->addWarning('composer', 'composer.lock not found locally - run composer install');
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
            syncStats: $this->syncStats,
            postDeployCommands: $this->config->postDeployCommands,
            success: $success,
            errorMessage: $errorMessage
        );

        $this->receiptService->save($receipt);
        $this->cmd->success('Deployment receipt saved');
    }

    /**
     * Check if command is an artisan shortcut (e.g., "config:cache")
     * vs a full shell command (e.g., "php artisan config:cache", "npm run build")
     */
    private function isArtisanShortcut(string $command): bool
    {
        // If command contains spaces, it's likely a full command
        // Artisan shortcuts are single words like "config:cache", "route:cache"
        if (str_contains($command, ' ')) {
            return false;
        }

        // Artisan commands typically contain a colon (namespace:command)
        // or are simple commands like "migrate", "optimize"
        return true;
    }
}
