<?php

namespace Shaf\LaravelDeployer\Concerns;

use Shaf\LaravelDeployer\Data\SyncDiff;
use Shaf\LaravelDeployer\Data\SyncStats;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\HooksService;

/**
 * Shared deployment step methods used by both DeployAction and SyncAction.
 *
 * Requires the using class to have:
 * - $this->cmd (CommandService)
 * - $this->config (DeploymentConfig)
 * - $this->rsync (RsyncService)
 * - $this->diff (DiffAction)
 * - $this->deployment (DeploymentService)
 * - $this->releasePath (string)
 * - $this->syncDiff (?SyncDiff)
 * - $this->syncStats (?SyncStats)
 * - $this->stepTimer (StepTimer)
 * - $this->hooks (?HooksService)
 * - $this->warnings (array)
 * - $this->migrationsRun (int)
 * - $this->gitCommitHash (?string)
 * - $this->gitCommitMessage (?string)
 * - $this->gitAuthor (?string)
 */
trait ManagesDeploymentSteps
{
    /**
     * Build frontend assets locally
     */
    protected function buildAssets(): void
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
     * Sync files to server with progress indicators
     */
    protected function syncFilesWithProgress(?string $filesFromPath = null): void
    {
        if ($this->config->showUploadProgress) {
            $diff = $this->syncDiff ?? new SyncDiff;
            $this->diff->showUploadProgress($diff);
        }

        $this->syncFilesToRelease($filesFromPath);

        if ($this->config->showUploadProgress) {
            $this->diff->showUploadComplete();
        }
    }

    /**
     * Sync files to server using rsync
     */
    protected function syncFilesToRelease(?string $filesFromPath = null): void
    {
        $this->cmd->task('files:sync');
        $this->cmd->info('Syncing files to server...');

        // Pass sync diff and output for progress bar
        $this->rsync->setSyncDiff($this->syncDiff);
        $this->rsync->setOutput($this->cmd->getOutput());

        $this->rsync->sync($this->releasePath, $filesFromPath);

        // Capture actual sync stats from rsync
        $this->syncStats = SyncStats::fromRsync($this->rsync, $this->syncDiff);

        $this->cmd->success('Files synced successfully');
    }

    /**
     * Verify that critical assets exist on the server after file sync.
     */
    protected function verifyAssets(): void
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

            $isDirectory = str_ends_with($assetPath, '/');

            if ($isDirectory) {
                $exists = $this->cmd->test("[ -d {$escapedPath} ]");
            } else {
                $exists = $this->cmd->test("[ -f {$escapedPath} ]");
            }

            if (! $exists) {
                $missingAssets[] = $assetPath;
            }
        }

        if (! empty($missingAssets)) {
            $this->cmd->warning('Missing assets: '.implode(', ', $missingAssets));
            $this->addWarning('assets', 'Missing assets: '.implode(', ', $missingAssets));
        } else {
            $count = count($assetsToVerify);
            $this->cmd->success("All {$count} critical asset(s) verified");
        }
    }

    /**
     * Create symlinks to shared directories
     */
    protected function createSharedLinks(): void
    {
        $this->cmd->task('shared:link');

        $sharedPath = $this->deployment->getSharedPath();

        $escapedReleaseStorage = CommandService::escapePath("{$this->releasePath}/storage");
        $escapedSharedStorage = CommandService::escapePath("{$sharedPath}/storage");
        $escapedSharedEnv = CommandService::escapePath("{$sharedPath}/.env");
        $escapedReleaseEnv = CommandService::escapePath("{$this->releasePath}/.env");

        $this->cmd->runBatch([
            "rm -rf {$escapedReleaseStorage}",
            "ln -nfs {$escapedSharedStorage} {$escapedReleaseStorage}",
            "ln -nfs {$escapedSharedEnv} {$escapedReleaseEnv}",
        ]);

        $this->cmd->success('Shared directories linked');
    }

    /**
     * Fix permissions on shared log files
     */
    protected function fixSharedLogPermissions(): void
    {
        $sharedPath = $this->deployment->getSharedPath();
        $logsPath = "{$sharedPath}/storage/logs";
        $escapedLogsPath = CommandService::escapePath($logsPath);

        $webGroup = $this->config->webGroup;
        $fileMode = $this->config->fileMode;
        $this->cmd->remote(
            "find {$escapedLogsPath} -name '*.log' -type f -exec chgrp {$webGroup} {} + -exec chmod {$fileMode} {} + 2>/dev/null || true"
        );
    }

    /**
     * Fix all permissions in a single SSH batch operation.
     */
    protected function fixPermissions(): void
    {
        $this->cmd->task('permissions:fix');

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
    protected function installComposerDependencies(): void
    {
        $this->cmd->task('composer:install');
        $this->cmd->info('Installing Composer dependencies...');

        $this->checkComposerLock();

        $composerOptions = $this->config->composerOptions ?? '--prefer-dist --no-interaction --no-scripts --no-plugins --no-dev --optimize-autoloader';
        $escapedPath = CommandService::escapePath($this->releasePath);

        $bootstrapCache = CommandService::escapePath("{$this->releasePath}/bootstrap/cache");
        $this->cmd->remote("rm -rf {$bootstrapCache} && mkdir -p {$bootstrapCache} && chmod 775 {$bootstrapCache}");

        $authJsonPath = "{$this->releasePath}/auth.json";
        if ($this->config->githubToken) {
            $this->createComposerAuthFile($authJsonPath);
        }

        try {
            $composerCommand = "cd {$escapedPath} && composer install {$composerOptions}";
            $output = $this->cmd->remoteWithOutput($composerCommand);

            $this->parseComposerWarnings($output);
        } finally {
            if ($this->config->githubToken) {
                $this->cmd->remote('rm -f '.CommandService::escapePath($authJsonPath));
            }
        }

        $this->cmd->success('Composer dependencies installed');
    }

    /**
     * Install composer dependencies for sync-only mode.
     */
    protected function installComposerForSyncOnly(): void
    {
        $this->cmd->task('composer:install');
        $this->cmd->info('Installing Composer dependencies...');

        $escapedPath = CommandService::escapePath($this->releasePath);
        $phpBinary = $this->config->phpBinary;
        $artisanPath = "{$this->releasePath}/artisan";

        $composerOptions = '--prefer-dist --no-interaction --no-scripts --optimize-autoloader';

        $originalOptions = $this->config->composerOptions ?? '';
        if (str_contains($originalOptions, '--no-dev') || ! str_contains($originalOptions, '--dev')) {
            $composerOptions .= ' --no-dev';
        }

        $composerCommand = "cd {$escapedPath} && composer install {$composerOptions}";
        $this->cmd->remoteWithOutput($composerCommand);

        $this->cmd->debug('Running package:discover for service provider registration...');
        $this->cmd->remote("cd {$escapedPath} && {$phpBinary} {$artisanPath} package:discover --ansi 2>/dev/null || true");

        $this->cmd->success('Composer dependencies installed');
    }

    /**
     * Run database migrations
     */
    protected function runMigrations(): void
    {
        $this->cmd->task('artisan:migrate');
        $this->cmd->info('Running database migrations...');

        $maintenanceEnabled = false;

        try {
            if ($this->config->backupBeforeMigrate) {
                $this->runPreMigrationBackup();
            }

            if ($this->config->maintenanceMode) {
                $this->enableMaintenanceMode();
                $maintenanceEnabled = true;
            }

            $result = $this->cmd->artisanMigrate($this->releasePath, force: true);
            $this->migrationsRun = $result['count'];

            $this->cmd->success('Migrations completed');

        } finally {
            if ($maintenanceEnabled) {
                $this->disableMaintenanceMode();
            }
        }
    }

    /**
     * Run optimization commands before symlinking release.
     */
    protected function runBeforeSymlinkCommands(): void
    {
        $beforeSymlink = $this->config->beforeSymlink ?? [];

        if (empty($beforeSymlink)) {
            return;
        }

        $this->validateBeforeSymlinkCommands();

        $this->cmd->task('optimize:release');
        $this->cmd->info('Running pre-symlink optimization commands...');

        $phpBinary = $this->config->phpBinary;
        $artisanPath = "{$this->releasePath}/artisan";

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

                if (str_contains($output, '  ERROR  ') || str_contains($output, 'Failed to clear cache')) {
                    throw new \RuntimeException("Command failed: {$displayCmd}");
                }
            } catch (\Exception $e) {
                $this->cmd->warning('  Command failed, attempting to fix permissions and retry...');

                try {
                    $this->fixBootstrapCachePermissions();
                    $output = $this->cmd->remoteWithOutput($fullCommand);

                    if (str_contains($output, '  ERROR  ') || str_contains($output, 'Failed to clear cache')) {
                        throw new \RuntimeException("Command failed after retry: {$displayCmd}\n{$output}");
                    }

                    $this->cmd->info('  Retry successful');
                } catch (\Exception $retryException) {
                    $this->cmd->error("  Command failed after retry: {$displayCmd}");
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
     * Create storage symlink in the release
     */
    protected function createStorageLink(): void
    {
        $this->cmd->task('storage:link');

        $publicStorageLink = "{$this->releasePath}/public/storage";

        if ($this->cmd->symlinkExists($publicStorageLink)) {
            $this->cmd->debug('Public storage symlink already exists, skipping');

            return;
        }

        $this->cmd->info('Creating storage symlink...');

        try {
            $this->cmd->artisanStorageLink($this->releasePath);
            $this->cmd->success('Storage symlink created');
        } catch (\Exception $e) {
            $this->cmd->warning("Storage link creation failed (may already exist): {$e->getMessage()}");
        }
    }

    /**
     * Clear caches for sync-only deployment.
     */
    protected function clearCachesForSyncOnly(): void
    {
        $this->cmd->task('cache:clear');
        $this->cmd->info('Clearing cached state from existing release...');

        $phpBinary = $this->config->phpBinary;
        $artisanPath = "{$this->releasePath}/artisan";
        $bootstrapCache = CommandService::escapePath("{$this->releasePath}/bootstrap/cache");

        $this->cmd->remote(
            "rm -rf {$bootstrapCache} && mkdir -p {$bootstrapCache} && chmod 775 {$bootstrapCache} && ".
            "cd {$this->releasePath} && {$phpBinary} {$artisanPath} optimize:clear --no-interaction 2>/dev/null || true"
        );

        $this->cmd->success('Caches cleared');
    }

    /**
     * Ensure storage structure exists for sync-only deployment.
     */
    protected function ensureStorageStructureForSyncOnly(): void
    {
        $storageLink = "{$this->releasePath}/storage";

        if ($this->cmd->symlinkExists($storageLink)) {
            return;
        }

        $this->cmd->debug('Storage symlink broken, recreating...');

        $sharedPath = $this->deployment->getSharedPath();
        $escapedSharedStorage = CommandService::escapePath("{$sharedPath}/storage");
        $escapedReleaseStorage = CommandService::escapePath($storageLink);
        $escapedSharedEnv = CommandService::escapePath("{$sharedPath}/.env");
        $escapedReleaseEnv = CommandService::escapePath("{$this->releasePath}/.env");

        $storageDirs = [
            "{$sharedPath}/storage",
            "{$sharedPath}/storage/app",
            "{$sharedPath}/storage/framework",
            "{$sharedPath}/storage/framework/cache",
            "{$sharedPath}/storage/framework/sessions",
            "{$sharedPath}/storage/framework/views",
            "{$sharedPath}/storage/logs",
        ];

        $escapedDirs = array_map([CommandService::class, 'escapePath'], $storageDirs);

        $this->cmd->runBatch([
            'mkdir -p '.implode(' ', $escapedDirs),
            "rm -rf {$escapedReleaseStorage}",
            "ln -nfs {$escapedSharedStorage} {$escapedReleaseStorage}",
            "ln -nfs {$escapedSharedEnv} {$escapedReleaseEnv}",
        ]);

        $this->cmd->debug('Storage symlink recreated');
    }

    /**
     * Run post-deployment hooks
     */
    protected function runPostDeploymentHooks(): void
    {
        $this->cmd->task('hooks:post-deploy');

        $this->validatePostDeployCommands();

        $postDeployCommands = $this->config->postDeployCommands;

        if (! empty($postDeployCommands)) {
            $this->cmd->info('Running post-deployment commands...');

            $phpBinary = $this->config->phpBinary;
            $artisanPath = "{$this->releasePath}/artisan";

            $batchedCommands = [];
            foreach ($postDeployCommands as $command) {
                if ($this->isArtisanShortcut($command)) {
                    $fullCommand = "{$phpBinary} {$artisanPath} {$command}";
                    $this->cmd->info("  → artisan {$command}");
                } else {
                    $fullCommand = "cd {$this->releasePath} && {$command}";
                    $this->cmd->info("  → {$command}");
                }
                $batchedCommands[] = $fullCommand;
            }

            $this->cmd->remoteWithOutput(implode(' && ', $batchedCommands));

            $this->cmd->success('Post-deployment commands completed');
        }

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
     * Cleanup SSH control master sockets
     */
    protected function cleanupSshSockets(): void
    {
        if ($this->config->isLocal) {
            return;
        }

        $this->cmd->debug('Cleaning up SSH control sockets...');

        $host = $this->config->hostname;
        $user = $this->config->remoteUser;
        $port = $this->config->port ?? 22;
        shell_exec("ssh -O exit -o ControlPath=/tmp/deployer-{$user}@{$host}:{$port} {$user}@{$host} 2>/dev/null || true");
    }

    /**
     * Capture git information from the current repository
     */
    protected function captureGitInfo(): void
    {
        $this->gitCommitHash = trim((string) shell_exec('git rev-parse --short HEAD 2>/dev/null'));
        $this->gitCommitMessage = trim((string) shell_exec('git log -1 --format=%s 2>/dev/null'));
        $this->gitAuthor = trim((string) shell_exec('git log -1 --format=%an 2>/dev/null'));

        $this->validateGitState();
    }

    /**
     * Validate git state and warn about potential issues
     */
    protected function validateGitState(): void
    {
        $status = trim((string) shell_exec('git status --porcelain 2>/dev/null'));
        if (! empty($status)) {
            $this->cmd->warning('Warning: Deploying with uncommitted changes');
            $this->addWarning('git', 'Uncommitted changes detected');
        }

        $currentBranch = trim((string) shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null'));
        if ($currentBranch && $currentBranch !== $this->config->branch) {
            $this->cmd->warning("Warning: On branch '{$currentBranch}' but config expects '{$this->config->branch}'");
            $this->addWarning('git', "Branch mismatch: on {$currentBranch}, config expects {$this->config->branch}");
        }
    }

    /**
     * Display git information at start of deployment
     */
    protected function showGitInfo(): void
    {
        if (! $this->gitCommitHash) {
            return;
        }

        $branch = $this->config->branch;
        $commit = $this->gitCommitHash;
        $author = $this->gitAuthor ?: 'Unknown';

        $this->cmd->info("Deploying: {$branch} @ {$commit} ({$author})");

        if ($this->gitCommitMessage) {
            $message = \Illuminate\Support\Str::limit($this->gitCommitMessage, 60);
            $this->cmd->info("   \"{$message}\"");
        }
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
     * Run a deployment hook if configured
     */
    protected function runHook(string $hookPoint): void
    {
        $this->hooks?->run($hookPoint);
    }

    /**
     * Add a deployment warning
     */
    protected function addWarning(string $category, string $message): void
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
     * Check if command is an artisan shortcut
     */
    protected function isArtisanShortcut(string $command): bool
    {
        if (str_contains($command, ' ')) {
            return false;
        }

        return true;
    }

    /**
     * Check if composer.lock exists locally and warn if missing.
     */
    protected function checkComposerLock(): void
    {
        $localLockFile = base_path('composer.lock');

        if (! file_exists($localLockFile)) {
            $this->cmd->warning('Warning: composer.lock not found locally!');
            $this->cmd->warning('   Run "composer install" to generate lock file for reproducible builds.');
            $this->addWarning('composer', 'composer.lock not found locally - run composer install');
        }
    }

    /**
     * Parse Composer output for known warnings
     */
    protected function parseComposerWarnings(string $output): void
    {
        if (str_contains($output, 'lock file is not up to date')) {
            $this->addWarning('composer', 'Lock file not up to date with composer.json');
        }

        if (preg_match('/Package\s+\S+\s+is abandoned/i', $output)) {
            $this->addWarning('composer', 'One or more packages are abandoned');
        }
    }

    /**
     * Create auth.json file for Composer authentication.
     */
    protected function createComposerAuthFile(string $authJsonPath): void
    {
        $authConfig = json_encode([
            'github-oauth' => [
                'github.com' => $this->config->githubToken,
            ],
        ], JSON_UNESCAPED_SLASHES);

        $escapedContent = escapeshellarg($authConfig);
        $escapedPath = CommandService::escapePath($authJsonPath);

        $this->cmd->remote("echo {$escapedContent} > {$escapedPath} && chmod 600 {$escapedPath}");
    }

    /**
     * Fix permissions on bootstrap/cache and shared storage
     */
    protected function fixBootstrapCachePermissions(): void
    {
        $bootstrapCache = CommandService::escapePath("{$this->releasePath}/bootstrap/cache");
        $sharedStorage = CommandService::escapePath("{$this->config->deployPath}/shared/storage");
        $webGroup = $this->config->webGroup;
        $deployUser = $this->config->remoteUser;

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
    protected function validateBeforeSymlinkCommands(): void
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

        if ($hasOptimize) {
            $this->cmd->warning('Redundant: `artisan optimize` detected in beforeSymlink');
            $this->addWarning('config', 'Redundant: artisan optimize in beforeSymlink (runs automatically after symlink)');
        }

        if ($hasCacheClear && $hasIndividualClears) {
            $this->cmd->warning('Redundant: Individual :clear commands with cache:clear');
            $this->addWarning('config', 'Redundant: Individual :clear commands with cache:clear');
        }

        if ($hasIndividualCaches && ! $hasOptimize) {
            $this->cmd->warning('Suboptimal: Individual :cache commands in beforeSymlink');
            $this->addWarning('config', 'Suboptimal: Individual :cache commands in beforeSymlink (may cause view errors)');
        }
    }

    /**
     * Validate postDeploy commands and warn about redundant configurations.
     */
    protected function validatePostDeployCommands(): void
    {
        $postDeployCommands = $this->config->postDeployCommands;

        if (empty($postDeployCommands)) {
            return;
        }

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
                    $this->cmd->warning("Redundant: `{$redundantCmd}` detected in postDeploy");
                    $this->addWarning('config', "Redundant: {$redundantCmd} in postDeploy ({$reason})");
                    break;
                }
            }
        }
    }

    /**
     * Run database backup before migrations
     */
    protected function runPreMigrationBackup(): void
    {
        $this->cmd->info('Creating pre-migration database backup...');

        try {
            $this->cmd->artisan('backup:run --only-db', $this->releasePath);
            $this->cmd->success('Pre-migration backup created');
        } catch (\Exception $e) {
            $this->cmd->warning("Pre-migration backup failed: {$e->getMessage()}");
            $this->cmd->warning('Continuing with migrations...');
        }
    }

    /**
     * Enable maintenance mode on the current release
     */
    protected function enableMaintenanceMode(): void
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
            $this->cmd->warning('Could not enable maintenance mode (may be first deploy)');
        }
    }

    /**
     * Disable maintenance mode
     */
    protected function disableMaintenanceMode(): void
    {
        $this->cmd->info('Disabling maintenance mode...');

        try {
            $this->cmd->artisan('up', $this->releasePath);
            $this->cmd->success('Maintenance mode disabled');
        } catch (\Exception $e) {
            $this->cmd->warning("Could not disable maintenance mode: {$e->getMessage()}");
        }
    }

    /**
     * Initialize hooks service if hooks are configured
     */
    protected function initializeHooks(): void
    {
        if (! empty($this->config->hooks)) {
            $this->hooks = new HooksService($this->cmd, $this->config);
            $this->hooks->loadHooks($this->config->hooks);
        }
    }
}
