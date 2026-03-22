<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;
use Shaf\LaravelDeployer\Services\SshService;

class SetupCommand extends Command
{
    protected $signature = 'deployer:setup
                            {action=install : Action to perform (install, init, keygen)}
                            {environment? : The environment (required for init)}
                            {email? : Email address for the SSH key (for keygen)}
                            {--name= : Custom name for the key pair (for keygen)}
                            {--force : Force generation of new key pair or skip confirmation}
                            {--dry-run : Show what would be done without executing (for init)}
                            {--skip-db-backup : Skip database backup (for init)}
                            {--skip-project-backup : Skip project files backup (for init)}
                            {--cleanup-only : Only run cleanup on already migrated site (for init)}';

    protected $description = 'Setup and initialization: install package, initialize existing site, generate SSH keys';

    private ?CommandService $cmd = null;

    private $config;

    private string $timestamp;

    private string $releaseName;

    private bool $dryRun = false;

    private bool $skipDbBackup = false;

    private bool $skipProjectBackup = false;

    private bool $cleanupOnly = false;

    private string $backupPath = '/var/www/backups';

    private array $dbCredentials = [];

    private ?string $sshDir = null;

    private ?string $defaultKeyPath = null;

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'install' => $this->handleInstall(),
            'init' => $this->handleInit(),
            'keygen' => $this->handleKeygen(),
            default => $this->showUsage(),
        };
    }

    // =========================================================================
    // INSTALL - Install Laravel Deployer configuration
    // =========================================================================

    protected function handleInstall(): int
    {
        $this->info('Installing Laravel Deployer...');

        $projectRoot = base_path();
        $deployDir = $projectRoot.'/.deploy';
        $deployJsonPath = $deployDir.'/deploy.json';
        $gitignorePath = $projectRoot.'/.gitignore';

        // Create .deploy directory first
        $this->createDeployDirectory($deployDir);

        // Generate deploy.json inside .deploy directory
        $this->generateDeployJson($deployJsonPath);

        // Update .gitignore (track deploy.json, ignore .env.*)
        $this->updateGitignore($gitignorePath);

        $this->newLine();
        $this->info('Laravel Deployer has been installed successfully!');
        $this->newLine();
        $this->info('Generated files:');
        $this->line('  .deploy/deploy.json - Main deployment configuration (tracked in git)');
        $this->line('  .deploy/.env.staging.example - Example secrets for staging');
        $this->line('  .deploy/.env.production.example - Example secrets for production');
        $this->line('  .deploy/.env.local.example - Example for local deployments');
        $this->newLine();
        $this->info('Next steps:');
        $this->line('1. Edit .deploy/deploy.json with your deployment settings');
        $this->line('2. Copy example files to actual environment files:');
        $this->line('   cp .deploy/.env.staging.example .deploy/.env.staging');
        $this->line('   cp .deploy/.env.production.example .deploy/.env.production');
        $this->line('3. Edit the .env files with your server credentials');
        $this->line('4. Run your first deployment:');
        $this->line('   php artisan deployer staging');
        $this->newLine();
        $this->info('Available commands:');
        $this->line('  php artisan deployer <env> - Deploy to specified environment');
        $this->line('  php artisan deployer:release rollback <env> - Rollback to previous release');
        $this->line('  php artisan deployer:db backup <env> - Backup database');
        $this->line('  php artisan deployer:db backup <env> --install - Backup + download + install locally');
        $this->line('  php artisan deployer:db download <env> - Download database backup');
        $this->line('  php artisan deployer:db install - Install backup to local database');
        $this->line('  php artisan deployer:server clear <env> - Clear caches on server');
        $this->newLine();

        return self::SUCCESS;
    }

    private function generateDeployJson(string $deployJsonPath): void
    {
        // Check if deploy.json already exists
        if (File::exists($deployJsonPath)) {
            $backupPath = $deployJsonPath.'.backup.'.date('Y-m-d_H-i-s');
            File::copy($deployJsonPath, $backupPath);
            $this->info('deploy.json already exists. Taking backup before creating new one.');
            $this->info('Backup saved to: '.basename($backupPath));
        }

        // Get the stub path
        $stubPath = __DIR__.'/../../stubs/deploy.json';

        if (! File::exists($stubPath)) {
            $this->error('deploy.json stub file not found at: '.$stubPath);

            return;
        }

        // Read the stub content
        $stubContent = File::get($stubPath);

        // Write the deploy.json file
        File::put($deployJsonPath, $stubContent);
        $this->info('.deploy/deploy.json generated');
    }

    private function createDeployDirectory(string $deployDir): void
    {
        // Create .deploy directory if it doesn't exist
        if (! File::exists($deployDir)) {
            File::makeDirectory($deployDir);
            $this->info('.deploy directory created');
        }

        // Generate .env.example files for environments
        $this->generateEnvExampleFile($deployDir.'/.env.staging.example', 'staging');
        $this->generateEnvExampleFile($deployDir.'/.env.production.example', 'production');
        $this->generateEnvExampleFile($deployDir.'/.env.local.example', 'local');
    }

    private function generateEnvExampleFile(string $envFilePath, string $environment): void
    {
        // Check if env file already exists
        if (File::exists($envFilePath)) {
            $this->warn(basename($envFilePath).' already exists. Skipping generation.');

            return;
        }

        // Get the stub path
        $stubPath = __DIR__."/../../stubs/.env.{$environment}.example";

        if (! File::exists($stubPath)) {
            $this->error(".env.{$environment}.example stub file not found at: {$stubPath}");

            return;
        }

        // Copy the stub content
        File::copy($stubPath, $envFilePath);
        $this->info(basename($envFilePath).' generated');
    }

    private function updateGitignore(string $gitignorePath): void
    {
        // New pattern: ignore all .deploy/* except deploy.json
        $gitignoreEntries = <<<'GITIGNORE'
# Laravel Deployer - track config, ignore secrets
.deploy/*
!.deploy/deploy.json
GITIGNORE;

        if (! File::exists($gitignorePath)) {
            File::put($gitignorePath, $gitignoreEntries.PHP_EOL);
            $this->info('.gitignore created with .deploy/ pattern');

            return;
        }

        $gitignoreContent = File::get($gitignorePath);

        // Check if already configured
        if (str_contains($gitignoreContent, '!.deploy/deploy.json')) {
            $this->info('.deploy/ already properly configured in .gitignore');

            return;
        }

        // Remove old .deploy/ entry if exists
        $gitignoreContent = preg_replace('/^\.deploy\/?\s*$/m', '', $gitignoreContent);

        // Add new entries
        $gitignoreContent = rtrim($gitignoreContent).PHP_EOL.PHP_EOL.$gitignoreEntries.PHP_EOL;
        File::put($gitignorePath, $gitignoreContent);
        $this->info('.deploy/ pattern updated in .gitignore (tracking deploy.json only)');
    }

    // =========================================================================
    // INIT - Migrate existing site to deployer structure
    // =========================================================================

    protected function handleInit(): int
    {
        $environment = $this->argument('environment');

        if (! $environment) {
            $this->error('Environment is required for init action.');
            $this->line('');
            $this->line('Usage: php artisan deployer:setup init {environment}');

            return self::FAILURE;
        }

        $this->dryRun = $this->option('dry-run');
        $this->skipDbBackup = $this->option('skip-db-backup');
        $this->skipProjectBackup = $this->option('skip-project-backup');
        $this->cleanupOnly = $this->option('cleanup-only');
        $force = $this->option('force');

        $this->timestamp = date('Ymd-His');
        $this->releaseName = date('Ym').'.1';

        try {
            // Load configuration
            $this->config = ConfigService::load($environment, base_path(), $this->output);
            $this->cmd = new CommandService($this->config, $this->output);

            // SAFETY: Never allow migrate command to run in local mode
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

            // Cleanup-only mode from CLI flag
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

            // Step 1: Pre-flight checks
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
            $this->components->error('Migration failed: '.$e->getMessage());

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

    private function preflightChecks(): bool
    {
        $this->components->info('Step 1/6: Running pre-flight checks...');

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
            $this->components->error('SSH connection failed. Please check your credentials and try again.');
            $this->newLine();

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
            $this->line('    1. Generate SSH key:     <fg=white>php artisan deployer:setup keygen</>');
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

            return true;
        });

        if ($alreadyMigrated) {
            $this->newLine();
            $this->components->warn('Site is already migrated. Running cleanup only...');
            $this->cleanupOnly = true;

            return true;
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

    private function preflightChecksForCleanup(): bool
    {
        $this->components->info('Running pre-flight checks for cleanup...');

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

    private function backupProject(): bool
    {
        $this->components->info('Step 2/6: Backing up project files...');

        $sitePath = $this->config->deployPath;
        $domain = basename($sitePath);
        $backupFile = "{$domain}-files-{$this->timestamp}.tar.gz";
        $backupFullPath = "{$this->backupPath}/{$backupFile}";

        $this->components->task('Creating backup directory', function () {
            if ($this->dryRun) {
                return true;
            }

            $deployUser = $this->config->remoteUser;
            $this->cmd->remote("sudo mkdir -p {$this->backupPath} && sudo chown {$deployUser}:{$deployUser} {$this->backupPath}");

            return true;
        });

        $this->components->task("Creating backup: {$backupFile}", function () use ($sitePath, $domain, $backupFullPath) {
            if ($this->dryRun) {
                return true;
            }

            $basePath = dirname($sitePath);
            $excludes = "--exclude='{$domain}/vendor' --exclude='{$domain}/node_modules' --exclude='{$domain}/.git' --exclude='{$domain}/storage/logs/*.log'";

            $this->cmd->remote("cd {$basePath} && sudo tar -czf {$backupFullPath} {$excludes} {$domain}");

            return $this->cmd->fileExists($backupFullPath);
        });

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

            return $this->cmd->test("[ -s {$backupFullPath} ]");
        });

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

    private function migrateStructure(): bool
    {
        $this->components->info('Step 4/6: Migrating directory structure...');

        $sitePath = $this->config->deployPath;
        $releasePath = "{$sitePath}/releases/{$this->releaseName}";

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

        $this->components->task("Moving files to release: {$this->releaseName}", function () use ($sitePath, $releasePath) {
            if ($this->dryRun) {
                return true;
            }

            $items = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'vendor', 'artisan', 'composer.json', 'composer.lock'];

            foreach ($items as $item) {
                $this->cmd->remote("if [ -e '{$sitePath}/{$item}' ]; then sudo mv '{$sitePath}/{$item}' '{$releasePath}/'; fi");
            }

            $this->cmd->remote("sudo mv {$sitePath}/*.php {$releasePath}/ 2>/dev/null || true");

            return true;
        });

        $this->components->task('Setting up shared storage', function () use ($sitePath, $releasePath) {
            if ($this->dryRun) {
                return true;
            }

            $this->cmd->remote("if [ -d '{$releasePath}/storage' ]; then sudo cp -an '{$releasePath}/storage/'* '{$sitePath}/shared/storage/' 2>/dev/null || true; fi");
            $this->cmd->remote("sudo rm -rf '{$releasePath}/storage'");

            $this->cmd->remote("if [ -f '{$sitePath}/.env' ]; then sudo cp '{$sitePath}/.env' '{$sitePath}/shared/.env'; fi");
            $this->cmd->remote("if [ -f '{$releasePath}/.env' ]; then sudo mv '{$releasePath}/.env' '{$sitePath}/shared/.env'; fi");

            $this->cmd->remote("sudo ln -sfn '{$sitePath}/shared/storage' '{$releasePath}/storage'");
            $this->cmd->remote("sudo ln -sfn '{$sitePath}/shared/.env' '{$releasePath}/.env'");

            return true;
        });

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

            $this->cmd->remote("sudo chown -R {$deployUser}:{$webUser} {$sitePath}");
            $this->cmd->remote("sudo chmod -R 775 {$sitePath}/shared/storage");
            $this->cmd->remote("sudo chown -R {$webUser}:{$webUser} {$sitePath}/shared/storage");
            $this->cmd->remote("sudo chown {$deployUser}:{$webUser} {$sitePath}/.dep");

            return true;
        });

        $this->newLine();

        return true;
    }

    private function cleanupLeftoverFiles(): bool
    {
        $this->components->info('Step 6/6: Cleaning up leftover files...');

        $sitePath = $this->config->deployPath;
        $keepItems = ['current', 'releases', 'shared', '.dep', '.env'];

        $this->components->task('Removing leftover files and directories', function () use ($sitePath, $keepItems) {
            if ($this->dryRun) {
                return true;
            }

            $excludes = implode(' ', array_map(fn ($item) => "-not -name '{$item}'", $keepItems));

            $this->cmd->remote(
                "cd {$sitePath} && find . -maxdepth 1 {$excludes} -not -name '.' -exec sudo rm -rf {} \\; 2>/dev/null || true"
            );

            return true;
        });

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
        $this->line('  3. Deploy using: <fg=white>php artisan deployer '.$this->config->environment->value.'</>');
        $this->newLine();
    }

    private function extractEnvValue(string $envContent, string $key): string
    {
        if (preg_match("/^{$key}=(.*)$/m", $envContent, $matches)) {
            return trim($matches[1], "\"'");
        }

        return '';
    }

    // =========================================================================
    // KEYGEN - Generate SSH key pair
    // =========================================================================

    protected function handleKeygen(): int
    {
        $this->info('🔑 SSH Key Generator for Laravel Deployer');
        $this->line('');

        // Ensure .ssh directory exists
        $this->ensureSshDirectory();

        // Get email (required for key generation)
        $email = $this->argument('email');
        if (! $email) {
            $email = $this->ask('Enter your email address for the SSH key', config('mail.from.address'));
        }

        if (! $email) {
            $this->error('❌ Email address is required for SSH key generation.');

            return self::FAILURE;
        }

        // Check if default key already exists
        if (! $this->option('force') && File::exists($this->getDefaultKeyPath().'.pub')) {
            return $this->handleExistingKey($email);
        }

        // Generate new key
        return $this->generateNewKey($email);
    }

    private function getSshDir(): string
    {
        if ($this->sshDir === null) {
            $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME') ?: null;

            if ($home === null) {
                throw new \RuntimeException('Could not determine home directory. Please set the HOME environment variable.');
            }

            $this->sshDir = $home.'/.ssh';
        }

        return $this->sshDir;
    }

    private function getDefaultKeyPath(): string
    {
        if ($this->defaultKeyPath === null) {
            $this->defaultKeyPath = $this->getSshDir().'/id_rsa';
        }

        return $this->defaultKeyPath;
    }

    private function ensureSshDirectory(): void
    {
        $sshDir = $this->getSshDir();

        if (! File::exists($sshDir)) {
            File::makeDirectory($sshDir, 0700, true);
            $this->info("✅ Created .ssh directory: {$sshDir}");
            $this->line('');
        }
    }

    private function handleExistingKey(string $email): int
    {
        $defaultKeyPath = $this->getDefaultKeyPath();

        $this->info("SSH key pair ({$defaultKeyPath}.pub) already exists.");
        $this->line('');

        $choice = $this->choice(
            'What would you like to do?',
            [
                'show' => 'Show Current Public Key',
                'generate' => 'Generate New Key Pair',
                'copy' => 'Copy Existing Key to Server',
                'cancel' => 'Cancel',
            ],
            'show'
        );

        return match ($choice) {
            'show' => $this->showPublicKey($defaultKeyPath.'.pub'),
            'generate' => $this->generateNewKey($email),
            'copy' => $this->copyKeyToServer($defaultKeyPath.'.pub'),
            default => self::SUCCESS,
        };
    }

    private function generateNewKey(string $email): int
    {
        $this->info("Generating a new SSH key pair for email: {$email}");
        $this->line('');

        $keyName = $this->option('name');
        if (! $keyName) {
            $keyName = $this->ask('Enter a name for the new key pair (default is id_rsa)', 'id_rsa');
        }

        $keyPath = $this->getSshDir().'/'.$keyName;

        if (File::exists($keyPath)) {
            if (! $this->confirm("Key {$keyName} already exists. Overwrite?", false)) {
                $this->info('ℹ️  Key generation cancelled.');

                return self::SUCCESS;
            }
        }

        $this->info('🔄 Generating SSH key pair...');

        $command = sprintf(
            'ssh-keygen -t rsa -b 4096 -C %s -f %s -N ""',
            escapeshellarg($email),
            escapeshellarg($keyPath)
        );

        $result = Process::run($command);

        if (! $result->successful()) {
            $this->error('❌ Failed to generate SSH key pair:');
            $this->line($result->errorOutput());

            return self::FAILURE;
        }

        $this->line('');
        $this->info('✅ SSH key pair generated successfully!');
        $this->line('');

        $this->showPublicKey($keyPath.'.pub');

        if ($this->confirm('Would you like to copy this key to a deployment server?', false)) {
            return $this->copyKeyToServer($keyPath.'.pub');
        }

        return self::SUCCESS;
    }

    private function showPublicKey(string $publicKeyPath): int
    {
        if (! File::exists($publicKeyPath)) {
            $this->error("❌ Public key not found: {$publicKeyPath}");

            return self::FAILURE;
        }

        $publicKey = trim(File::get($publicKeyPath));

        $this->line('');
        $this->info('📋 Your Public SSH Key:');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line($publicKey);
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('');

        $this->info('💡 Next Steps:');
        $this->line('');
        $this->line('   1. Copy the key above to your deployment server');
        $this->line('   2. Add it to ~/.ssh/authorized_keys on the server');
        $this->line('   3. Or use: ssh-copy-id -i '.$publicKeyPath.' user@server');
        $this->line('');
        $this->comment('   For GitHub/GitLab/Bitbucket:');
        $this->line('   • Add this key to your repository deploy keys');
        $this->line('   • GitHub: Settings → Deploy keys → Add deploy key');
        $this->line('   • GitLab: Settings → Repository → Deploy keys');
        $this->line('');

        $this->offerClipboardCopy($publicKey);

        return self::SUCCESS;
    }

    private function copyKeyToServer(string $publicKeyPath): int
    {
        $this->line('');
        $this->info('📤 Copy SSH Key to Server');
        $this->line('');

        $suggestedServers = $this->getSuggestedServers();

        if (! empty($suggestedServers)) {
            $this->info('💡 Available deployment servers from your configuration:');
            foreach ($suggestedServers as $env => $server) {
                $this->line("   • {$env}: {$server['user']}@{$server['hostname']}");
            }
            $this->line('');
        }

        $hostname = $this->ask('Enter server hostname or IP address');
        if (! $hostname) {
            $this->info('ℹ️  Cancelled.');

            return self::SUCCESS;
        }

        $username = $this->ask('Enter username for the server', 'deploy');
        if (! $username) {
            $this->info('ℹ️  Cancelled.');

            return self::SUCCESS;
        }

        $this->info("🔄 Copying SSH key to {$username}@{$hostname}...");

        $publicKey = trim(File::get($publicKeyPath));

        // Use SSH to append key directly (cross-platform, no ssh-copy-id dependency)
        $sshService = new SshService(
            host: $hostname,
            user: $username,
            strictHostKeyChecking: false,
            timeout: 60,
        );

        $appendCommand = implode(' && ', [
            'mkdir -p ~/.ssh',
            'chmod 700 ~/.ssh',
            'echo '.escapeshellarg($publicKey).' >> ~/.ssh/authorized_keys',
            'chmod 600 ~/.ssh/authorized_keys',
        ]);

        $result = $sshService->ssh($appendCommand);

        if ($result->successful) {
            $this->line('');
            $this->info("✅ SSH key successfully copied to {$username}@{$hostname}!");
            $this->line('');
            $this->info('You can now deploy without password authentication:');
            $this->line("   ssh {$username}@{$hostname}");
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('');
        $this->warn('⚠️  Automatic copy failed. You can copy manually:');
        $this->line('');

        $this->line('1. Connect to your server:');
        $this->line("   ssh {$username}@{$hostname}");
        $this->line('');
        $this->line('2. Run these commands on the server:');
        $this->line('   mkdir -p ~/.ssh');
        $this->line('   chmod 700 ~/.ssh');
        $this->line("   echo '{$publicKey}' >> ~/.ssh/authorized_keys");
        $this->line('   chmod 600 ~/.ssh/authorized_keys');
        $this->line('');

        return self::SUCCESS;
    }

    private function getSuggestedServers(): array
    {
        $servers = [];
        $deployConfigPath = base_path('.deploy/deploy.json');

        if (! File::exists($deployConfigPath)) {
            return $servers;
        }

        try {
            $config = json_decode(File::get($deployConfigPath), true);

            if (isset($config['environments']) && is_array($config['environments'])) {
                foreach ($config['environments'] as $env => $envConfig) {
                    // Skip local environments
                    if (! empty($envConfig['local'])) {
                        continue;
                    }

                    // Server info comes from .env files, so just return env names
                    $servers[$env] = [
                        'environment' => $env,
                        'deployPath' => $envConfig['deployPath'] ?? '',
                    ];
                }
            }
        } catch (\Exception $e) {
            // Silently fail - config parsing is optional
        }

        return $servers;
    }

    private function offerClipboardCopy(string $publicKey): void
    {
        $clipboardCmd = null;

        if (PHP_OS_FAMILY === 'Linux') {
            $result = Process::run('which xclip 2>/dev/null');
            if ($result->successful()) {
                $clipboardCmd = 'xclip -selection clipboard';
            } else {
                $result = Process::run('which xsel 2>/dev/null');
                if ($result->successful()) {
                    $clipboardCmd = 'xsel --clipboard --input';
                }
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $clipboardCmd = 'pbcopy';
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $clipboardCmd = 'clip';
        }

        if ($clipboardCmd && $this->confirm('Copy public key to clipboard?', false)) {
            $result = Process::run('echo '.escapeshellarg($publicKey)." | {$clipboardCmd}");

            if ($result->successful()) {
                $this->info('✅ Public key copied to clipboard!');
            } else {
                $this->warn('⚠️  Could not copy to clipboard. Please copy manually.');
            }
        }
    }

    protected function showUsage(): int
    {
        $this->error('Invalid action. Available actions:');
        $this->line('');
        $this->line('  php artisan deployer:setup install              Install Laravel Deployer config');
        $this->line('  php artisan deployer:setup init {env}           Initialize existing site structure');
        $this->line('  php artisan deployer:setup keygen [email]       Generate SSH key pair');
        $this->line('');
        $this->line('Examples:');
        $this->line('  php artisan deployer:setup install');
        $this->line('  php artisan deployer:setup init staging');
        $this->line('  php artisan deployer:setup init production --dry-run');
        $this->line('  php artisan deployer:setup keygen user@example.com');

        return self::FAILURE;
    }
}
