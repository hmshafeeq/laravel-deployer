<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;

class MigrateCommand extends Command
{
    protected $signature = 'deployer:migrate {environment=staging : The environment to migrate (staging, production)}
                            {--dry-run : Show what would be done without executing}
                            {--skip-db-backup : Skip database backup}
                            {--skip-project-backup : Skip project files backup}
                            {--cleanup-only : Only run cleanup on already migrated site}
                            {--force : Skip confirmation prompts}';

    protected $description = 'Migrate an existing Laravel deployment to laravel-deployer directory structure';

    private CommandService $cmd;

    private $config;

    private string $timestamp;

    private string $releaseName;

    private bool $dryRun = false;

    private bool $skipDbBackup = false;

    private bool $skipProjectBackup = false;

    private bool $cleanupOnly = false;

    private string $backupPath = '/var/www/backups';

    private array $dbCredentials = [];

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $this->dryRun = $this->option('dry-run');
        $this->skipDbBackup = $this->option('skip-db-backup');
        $this->skipProjectBackup = $this->option('skip-project-backup');
        $this->cleanupOnly = $this->option('cleanup-only');
        $force = $this->option('force');

        $this->timestamp = date('Ymd-His');
        $this->releaseName = date('Ym') . '.1';

        try {
            // Load configuration
            $this->config = ConfigService::load($environment, base_path());
            $this->cmd = new CommandService($this->config, $this->output);

            // SAFETY: Never allow migrate command to run in local mode
            // This prevents accidental deletion of local project files
            if ($this->config->isLocal) {
                $this->components->error('Migration cannot run in local mode!');
                $this->components->error('Local mode would execute destructive commands on your local machine.');
                $this->newLine();
                $this->line('Please use a remote environment (staging or production) instead.');

                return self::FAILURE;
            }

            // Show migration info
            $this->showMigrationInfo();

            // Confirm migration
            if (! $force && ! $this->confirmMigration()) {
                $this->newLine();
                $this->components->warn('Migration cancelled by user');

                return self::FAILURE;
            }

            // Execute migration steps
            $this->newLine();

            // Cleanup-only mode from CLI flag: just run cleanup on already migrated site
            if ($this->cleanupOnly) {
                if (! $this->preflightChecksForCleanup()) {
                    return self::FAILURE;
                }

                if (! $this->cleanupLeftoverFiles()) {
                    return self::FAILURE;
                }

                $this->newLine();
                $this->components->info('Cleanup completed successfully!');

                return self::SUCCESS;
            }

            // Step 1: Pre-flight checks (may set cleanupOnly if already migrated)
            if (! $this->preflightChecks()) {
                return self::FAILURE;
            }

            // If preflightChecks detected already migrated site, run cleanup only
            if ($this->cleanupOnly) {
                if (! $this->cleanupLeftoverFiles()) {
                    return self::FAILURE;
                }

                $this->newLine();
                $this->components->info('Cleanup completed successfully!');

                return self::SUCCESS;
            }

            // Step 2: Backup project files
            if (! $this->skipProjectBackup && ! $this->backupProject()) {
                return self::FAILURE;
            }

            // Step 3: Backup database
            if (! $this->skipDbBackup && ! $this->backupDatabase()) {
                return self::FAILURE;
            }

            // Step 4: Migrate directory structure
            if (! $this->migrateStructure()) {
                return self::FAILURE;
            }

            // Step 5: Set permissions
            if (! $this->setPermissions()) {
                return self::FAILURE;
            }

            // Step 6: Cleanup leftover files
            if (! $this->cleanupLeftoverFiles()) {
                return self::FAILURE;
            }

            // Show success summary
            $this->showSuccessSummary();

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->newLine();
            $this->components->error('Migration failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function showMigrationInfo(): void
    {
        $this->newLine();
        $this->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->line('<fg=cyan>            Laravel Deployer - Site Migration</>');
        $this->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->newLine();

        $this->line("  <info>Environment:</info>   <fg=white>{$this->config->environment->value}</>");
        $this->line("  <info>Server:</info>        <fg=white>{$this->config->remoteUser}@{$this->config->hostname}</>");
        $this->line("  <info>Deploy Path:</info>   <fg=white>{$this->config->deployPath}</>");
        $this->line("  <info>Release Name:</info>  <fg=white>{$this->releaseName}</>");

        if ($this->dryRun) {
            $this->newLine();
            $this->line('  <fg=yellow>⚠ DRY-RUN MODE - No changes will be made</>');
        }

        $this->newLine();
        $this->line('  <fg=yellow>⚠ Prerequisites:</>');
        $this->line("    - Traditional deployment structure ({$this->config->deployPath}/public)");
        $this->line("    - Nginx config pointing to {$this->config->deployPath}/public");
        $this->line('    - NOT already using releases/current symlinks');

        $this->newLine();
        $this->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->newLine();
    }

    private function confirmMigration(): bool
    {
        $this->components->warn('This will restructure the deployment directory on the server.');
        $this->newLine();

        return $this->confirm('Do you want to proceed with the migration?', false);
    }

    // =========================================================================
    // Step 1: Pre-flight Checks
    // =========================================================================

    private function preflightChecks(): bool
    {
        $this->components->info('Step 1/6: Running pre-flight checks...');

        // Check SSH connection - exit immediately if this fails
        $sshConnected = false;
        $this->components->task('Testing SSH connection', function () use (&$sshConnected) {
            if ($this->dryRun) {
                $sshConnected = true;

                return true;
            }

            $sshConnected = $this->cmd->test('echo "connected"');

            return $sshConnected;
        });

        if (! $sshConnected) {
            $this->newLine();
            $this->components->error('SSH connection failed. Please check your credentials and try again.');
            $this->newLine();

            // Debug: show identity file status and actual error
            if ($this->config->identityFile) {
                $this->line("  <fg=gray>Identity file: {$this->config->identityFile}</>");
            } else {
                $this->line('  <fg=yellow>No identity file configured. Add DEPLOY_IDENTITY_FILE to .deploy/.env.staging</>');
            }

            if ($error = $this->cmd->getLastError()) {
                $this->line("  <fg=red>Error: {$error}</>");
            }
            $this->newLine();

            $this->line('  <fg=cyan>To resolve this issue:</>');
            $this->line('    1. Generate SSH key:     <fg=white>php artisan deploy:key-generate</>');
            $this->line("    2. Or copy existing key: <fg=white>ssh-copy-id {$this->config->remoteUser}@{$this->config->hostname}</>");
            $this->line("    3. Test connection:      <fg=white>ssh {$this->config->remoteUser}@{$this->config->hostname} echo 'connected'</>");
            $this->newLine();

            return false;
        }

        // Check site path exists
        $sitePath = $this->config->deployPath;
        $siteExists = false;
        $this->components->task("Checking site path exists: {$sitePath}", function () use ($sitePath, &$siteExists) {
            if ($this->dryRun) {
                $siteExists = true;

                return true;
            }

            $siteExists = $this->cmd->directoryExists($sitePath);

            return $siteExists;
        });

        if (! $siteExists) {
            $this->newLine();
            $this->components->error("Site path does not exist: {$sitePath}");

            return false;
        }

        // Check if already migrated
        $alreadyMigrated = false;
        $this->components->task('Checking migration status', function () use ($sitePath, &$alreadyMigrated) {
            if ($this->dryRun) {
                return true;
            }

            $alreadyMigrated = $this->cmd->directoryExists("{$sitePath}/releases");

            return true; // Always pass, we handle both cases
        });

        if ($alreadyMigrated) {
            $this->newLine();
            $this->components->warn('Site is already migrated. Running cleanup only...');
            $this->cleanupOnly = true;

            return true; // Continue to cleanup
        }

        // Check Laravel installation
        $this->components->task('Detecting Laravel installation', function () use ($sitePath) {
            if ($this->dryRun) {
                return true;
            }

            return $this->cmd->fileExists("{$sitePath}/artisan");
        });

        // Read database credentials from .env
        if (! $this->skipDbBackup) {
            $this->components->task('Reading database credentials from .env', function () use ($sitePath) {
                if ($this->dryRun) {
                    $this->dbCredentials = ['name' => 'database', 'user' => 'user', 'pass' => 'pass'];

                    return true;
                }

                try {
                    $envContent = $this->cmd->remote("cat {$sitePath}/.env 2>/dev/null || echo ''");

                    $this->dbCredentials = [
                        'name' => $this->extractEnvValue($envContent, 'DB_DATABASE'),
                        'user' => $this->extractEnvValue($envContent, 'DB_USERNAME'),
                        'pass' => $this->extractEnvValue($envContent, 'DB_PASSWORD'),
                    ];

                    return ! empty($this->dbCredentials['name']) && ! empty($this->dbCredentials['user']);
                } catch (\Exception $e) {
                    return false;
                }
            });

            if (empty($this->dbCredentials['name']) && ! $this->dryRun) {
                $this->components->warn('Could not detect database credentials. Database backup will be skipped.');
                $this->skipDbBackup = true;
            }
        }

        $this->newLine();

        return true;
    }

    // =========================================================================
    // Pre-flight Checks for Cleanup-Only Mode
    // =========================================================================

    private function preflightChecksForCleanup(): bool
    {
        $this->components->info('Running pre-flight checks for cleanup...');

        // Check SSH connection
        $sshConnected = false;
        $this->components->task('Testing SSH connection', function () use (&$sshConnected) {
            if ($this->dryRun) {
                $sshConnected = true;

                return true;
            }

            $sshConnected = $this->cmd->test('echo "connected"');

            return $sshConnected;
        });

        if (! $sshConnected) {
            $this->newLine();
            $this->components->error('SSH connection failed.');

            return false;
        }

        // Check site path exists
        $sitePath = $this->config->deployPath;
        $siteExists = false;
        $this->components->task("Checking site path exists: {$sitePath}", function () use ($sitePath, &$siteExists) {
            if ($this->dryRun) {
                $siteExists = true;

                return true;
            }

            $siteExists = $this->cmd->directoryExists($sitePath);

            return $siteExists;
        });

        if (! $siteExists) {
            $this->newLine();
            $this->components->error("Site path does not exist: {$sitePath}");

            return false;
        }

        // Check site IS migrated (releases directory must exist for cleanup)
        $isMigrated = false;
        $this->components->task('Checking site is migrated', function () use ($sitePath, &$isMigrated) {
            if ($this->dryRun) {
                $isMigrated = true;

                return true;
            }

            $isMigrated = $this->cmd->directoryExists("{$sitePath}/releases");

            return $isMigrated;
        });

        if (! $isMigrated) {
            $this->newLine();
            $this->components->error('Site is not migrated yet. Run without --cleanup-only first.');

            return false;
        }

        $this->newLine();

        return true;
    }

    // =========================================================================
    // Step 2: Backup Project Files
    // =========================================================================

    private function backupProject(): bool
    {
        $this->components->info('Step 2/6: Backing up project files...');

        $sitePath = $this->config->deployPath;
        $domain = basename($sitePath);
        $backupFile = "{$domain}-files-{$this->timestamp}.tar.gz";
        $backupFullPath = "{$this->backupPath}/{$backupFile}";

        // Create backup directory with proper permissions
        $this->components->task('Creating backup directory', function () {
            if ($this->dryRun) {
                return true;
            }

            $deployUser = $this->config->remoteUser;
            $this->cmd->remote("sudo mkdir -p {$this->backupPath} && sudo chown {$deployUser}:{$deployUser} {$this->backupPath}");

            return true;
        });

        // Create tarball
        $this->components->task("Creating backup: {$backupFile}", function () use ($sitePath, $domain, $backupFullPath) {
            if ($this->dryRun) {
                return true;
            }

            $basePath = dirname($sitePath);
            // Exclude vendor, node_modules, .git for speed. Hidden files like .env ARE included.
            $excludes = "--exclude='{$domain}/vendor' --exclude='{$domain}/node_modules' --exclude='{$domain}/.git' --exclude='{$domain}/storage/logs/*.log'";

            $this->cmd->remote("cd {$basePath} && sudo tar -czf {$backupFullPath} {$excludes} {$domain}");

            // Verify backup was created
            return $this->cmd->fileExists($backupFullPath);
        });

        // Show backup size
        if (! $this->dryRun) {
            try {
                $size = trim($this->cmd->remote("ls -lh {$backupFullPath} | awk '{print \$5}'"));
                $this->line("    <fg=gray>Backup size: {$size}</>");
            } catch (\Exception $e) {
                // Ignore
            }
        }

        $this->newLine();

        return true;
    }

    // =========================================================================
    // Step 3: Backup Database
    // =========================================================================

    private function backupDatabase(): bool
    {
        $this->components->info('Step 3/6: Backing up database...');

        if ($this->skipDbBackup) {
            $this->components->warn('Database backup skipped');
            $this->newLine();

            return true;
        }

        $domain = basename($this->config->deployPath);
        $backupFile = "{$domain}-database-{$this->timestamp}.sql.gz";
        $backupFullPath = "{$this->backupPath}/{$backupFile}";

        $this->components->task("Creating database backup: {$backupFile}", function () use ($backupFullPath) {
            if ($this->dryRun) {
                return true;
            }

            $dbName = escapeshellarg($this->dbCredentials['name']);
            $dbUser = escapeshellarg($this->dbCredentials['user']);
            $dbPass = escapeshellarg($this->dbCredentials['pass']);

            $this->cmd->remote("mysqldump -u{$dbUser} -p{$dbPass} {$dbName} 2>/dev/null | gzip > {$backupFullPath}");

            // Verify backup was created and not empty
            return $this->cmd->test("[ -s {$backupFullPath} ]");
        });

        // Show backup size
        if (! $this->dryRun) {
            try {
                $size = trim($this->cmd->remote("ls -lh {$backupFullPath} | awk '{print \$5}'"));
                $this->line("    <fg=gray>Backup size: {$size}</>");
            } catch (\Exception $e) {
                // Ignore
            }
        }

        $this->newLine();

        return true;
    }

    // =========================================================================
    // Step 4: Migrate Directory Structure
    // =========================================================================

    private function migrateStructure(): bool
    {
        $this->components->info('Step 4/6: Migrating directory structure...');

        $sitePath = $this->config->deployPath;
        $releasePath = "{$sitePath}/releases/{$this->releaseName}";

        // Create directory structure
        $this->components->task('Creating directory structure', function () use ($sitePath, $releasePath) {
            if ($this->dryRun) {
                return true;
            }

            $dirs = [
                $releasePath,
                "{$sitePath}/shared/storage/app/public",
                "{$sitePath}/shared/storage/framework/cache/data",
                "{$sitePath}/shared/storage/framework/sessions",
                "{$sitePath}/shared/storage/framework/views",
                "{$sitePath}/shared/storage/logs",
                "{$sitePath}/.dep",
            ];

            foreach ($dirs as $dir) {
                $this->cmd->remote("sudo mkdir -p {$dir}");
            }

            return true;
        });

        // Move Laravel files to release
        $this->components->task("Moving files to release: {$this->releaseName}", function () use ($sitePath, $releasePath) {
            if ($this->dryRun) {
                return true;
            }

            $items = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'vendor', 'artisan', 'composer.json', 'composer.lock'];

            foreach ($items as $item) {
                $this->cmd->remote("if [ -e '{$sitePath}/{$item}' ]; then sudo mv '{$sitePath}/{$item}' '{$releasePath}/'; fi");
            }

            // Move any PHP files in root
            $this->cmd->remote("sudo mv {$sitePath}/*.php {$releasePath}/ 2>/dev/null || true");

            return true;
        });

        // Setup shared storage
        $this->components->task('Setting up shared storage', function () use ($sitePath, $releasePath) {
            if ($this->dryRun) {
                return true;
            }

            // Copy storage to shared
            $this->cmd->remote("if [ -d '{$releasePath}/storage' ]; then sudo cp -an '{$releasePath}/storage/'* '{$sitePath}/shared/storage/' 2>/dev/null || true; fi");
            $this->cmd->remote("sudo rm -rf '{$releasePath}/storage'");

            // Copy .env from site root to shared (primary location for traditional deployments)
            $this->cmd->remote("if [ -f '{$sitePath}/.env' ]; then sudo cp '{$sitePath}/.env' '{$sitePath}/shared/.env'; fi");

            // Also check release path as fallback
            $this->cmd->remote("if [ -f '{$releasePath}/.env' ]; then sudo mv '{$releasePath}/.env' '{$sitePath}/shared/.env'; fi");

            // Create symlinks
            $this->cmd->remote("sudo ln -sfn '{$sitePath}/shared/storage' '{$releasePath}/storage'");
            $this->cmd->remote("sudo ln -sfn '{$sitePath}/shared/.env' '{$releasePath}/.env'");

            return true;
        });

        // Create current symlink
        $this->components->task('Creating current symlink', function () use ($sitePath, $releasePath) {
            if ($this->dryRun) {
                return true;
            }

            $this->cmd->remote("sudo ln -sfn '{$releasePath}' '{$sitePath}/current'");

            return true;
        });

        $this->newLine();

        return true;
    }

    // =========================================================================
    // Step 5: Set Permissions
    // =========================================================================

    private function setPermissions(): bool
    {
        $this->components->info('Step 5/6: Setting permissions...');

        $sitePath = $this->config->deployPath;
        $deployUser = $this->config->remoteUser;
        $webUser = 'www-data';

        $this->components->task('Setting ownership and permissions', function () use ($sitePath, $deployUser, $webUser) {
            if ($this->dryRun) {
                return true;
            }

            // Set ownership
            $this->cmd->remote("sudo chown -R {$deployUser}:{$webUser} {$sitePath}");

            // Storage writable by web server
            $this->cmd->remote("sudo chmod -R 775 {$sitePath}/shared/storage");
            $this->cmd->remote("sudo chown -R {$webUser}:{$webUser} {$sitePath}/shared/storage");

            // .dep directory
            $this->cmd->remote("sudo chown {$deployUser}:{$webUser} {$sitePath}/.dep");

            return true;
        });

        $this->newLine();

        return true;
    }

    // =========================================================================
    // Step 6: Cleanup Leftover Files
    // =========================================================================

    private function cleanupLeftoverFiles(): bool
    {
        $this->components->info('Step 6/6: Cleaning up leftover files...');

        $sitePath = $this->config->deployPath;

        // These are the only directories/files that should remain
        $keepItems = ['current', 'releases', 'shared', '.dep', '.env'];

        $this->components->task('Removing leftover files and directories', function () use ($sitePath, $keepItems) {
            if ($this->dryRun) {
                return true;
            }

            // Build find command to exclude the items we want to keep
            // This finds all items in the root of sitePath except the ones we want to keep
            $excludes = implode(' ', array_map(fn ($item) => "-not -name '{$item}'", $keepItems));

            // Find and remove files/directories in site root (not recursively into subdirs)
            // -maxdepth 1 ensures we only look at the root level
            $this->cmd->remote(
                "cd {$sitePath} && find . -maxdepth 1 {$excludes} -not -name '.' -exec sudo rm -rf {} \\; 2>/dev/null || true"
            );

            return true;
        });

        // Show what remains
        if (! $this->dryRun) {
            try {
                $remaining = trim($this->cmd->remote("ls -la {$sitePath} | tail -n +4 | awk '{print \$NF}' | tr '\\n' ' '"));
                $this->line("    <fg=gray>Remaining: {$remaining}</>");
            } catch (\Exception $e) {
                // Ignore
            }
        }

        $this->newLine();

        return true;
    }

    // =========================================================================
    // Success Summary
    // =========================================================================

    private function showSuccessSummary(): void
    {
        $sitePath = $this->config->deployPath;
        $domain = basename($sitePath);

        $this->newLine();
        $this->line('<fg=green>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->line('<fg=green>            Migration Completed Successfully!</>');
        $this->line('<fg=green>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->newLine();

        $this->line('<fg=cyan>Final Structure:</>');
        $this->line("  {$sitePath}/");
        $this->line("  ├── current -> releases/{$this->releaseName}");
        $this->line('  ├── releases/');
        $this->line("  │   └── {$this->releaseName}/");
        $this->line('  ├── shared/');
        $this->line('  │   ├── storage/');
        $this->line('  │   └── .env');
        $this->line('  └── .dep/');
        $this->newLine();

        if (! $this->skipProjectBackup || ! $this->skipDbBackup) {
            $this->line('<fg=cyan>Backups:</>');
            if (! $this->skipProjectBackup) {
                $this->line("  Project:  {$this->backupPath}/{$domain}-files-{$this->timestamp}.tar.gz");
            }
            if (! $this->skipDbBackup) {
                $this->line("  Database: {$this->backupPath}/{$domain}-database-{$this->timestamp}.sql.gz");
            }
            $this->newLine();
        }

        $this->line('<fg=yellow>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->line('<fg=yellow>            IMPORTANT: Update Nginx Configuration</>');
        $this->line('<fg=yellow>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->newLine();
        $this->line('  Change document root in your nginx config:');
        $this->newLine();
        $this->line('    <fg=red># FROM:</>');
        $this->line("    <fg=gray>root {$sitePath}/public;</>");
        $this->newLine();
        $this->line('    <fg=green># TO:</>');
        $this->line("    <fg=white>root {$sitePath}/current/public;</>");
        $this->newLine();
        $this->line('  Then reload nginx:');
        $this->line('    <fg=white>sudo nginx -t && sudo systemctl reload nginx</>');
        $this->newLine();

        $this->line('<fg=cyan>Next Steps:</>');
        $this->line('  1. Update nginx configuration (see above)');
        $this->line('  2. Test the site is working');
        $this->line('  3. Deploy using: <fg=white>php artisan deploy ' . $this->config->environment->value . '</>');
        $this->newLine();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function extractEnvValue(string $envContent, string $key): string
    {
        if (preg_match("/^{$key}=(.*)$/m", $envContent, $matches)) {
            return trim($matches[1], "\"'");
        }

        return '';
    }
}
