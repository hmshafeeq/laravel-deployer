<?php

namespace Shaf\LaravelDeployer\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Shaf\LaravelDeployer\Actions\DatabaseAction;
use Shaf\LaravelDeployer\Concerns\ManagesLocalBackups;
use Shaf\LaravelDeployer\Concerns\SelectsServer;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;
use Shaf\LaravelDeployer\Services\SshService;

class DatabaseCommand extends Command
{
    use ManagesLocalBackups, SelectsServer;

    protected $signature = 'deployer:db
                            {action : Action to perform (backup, download, upload, install, list)}
                            {target? : Server name for backup/download, or backup file for install}
                            {--select : Show available servers and select interactively}
                            {--latest : Use the latest backup}
                            {--backup : Create a new backup before downloading (for download action)}
                            {--download : Download the backup after creating it (for backup action)}
                            {--install : Download and install the backup locally (for backup action)}
                            {--force : Skip confirmation prompts}
                            {--target-server= : Remote server for upload (user@host)}
                            {--key= : SSH key path for upload}
                            {--path= : Remote destination path for upload}
                            {--no-migrate : Skip running migrations after install}';

    protected $description = 'Database operations: backup, download, upload, install';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'backup' => $this->handleBackup(),
            'download' => $this->handleDownload(),
            'upload' => $this->handleUpload(),
            'install', 'restore' => $this->handleInstall(),
            'list' => $this->handleList(),
            default => $this->showUsage(),
        };
    }

    // =========================================================================
    // BACKUP - Create backup on remote server
    // =========================================================================

    protected function handleBackup(): int
    {
        $shouldDownload = $this->option('download') || $this->option('install');
        $shouldInstall = $this->option('install');

        $this->info('Database Backup');
        $this->line('');

        $serverName = $this->argument('target') ?? $this->getServerName();
        if (! $serverName) {
            return self::FAILURE;
        }

        $this->info("Target server: {$serverName}");
        $this->line('');

        try {
            $config = ConfigService::load($serverName, base_path(), $this->output);
            $cmdService = new CommandService($config, $this->output);
            $database = new DatabaseAction($cmdService, $config);

            $backupFile = $database->backup();

            $this->line('');
            $this->info('Database backup completed successfully!');
            $this->info("Backup file: {$backupFile}");

            if (! $shouldDownload) {
                $this->line('');
                $this->info('To download: php artisan deployer:db download '.$serverName);

                return self::SUCCESS;
            }

            // Download the backup
            $localFile = $this->downloadRemoteBackup($backupFile, $config);
            if (! $localFile) {
                return self::FAILURE;
            }

            if (! $shouldInstall) {
                $this->line('');
                $this->info('To install: php artisan deployer:db install --latest');

                return self::SUCCESS;
            }

            // Install locally
            return $this->installLocalBackup($localFile);
        } catch (\Exception $e) {
            $this->error('Database backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    // =========================================================================
    // DOWNLOAD - Download backup from remote server
    // =========================================================================

    protected function handleDownload(): int
    {
        $this->info('Database Download');
        $this->line('');

        $serverName = $this->argument('target') ?? $this->getServerName();
        if (! $serverName) {
            return self::FAILURE;
        }

        $this->info("Target server: {$serverName}");
        $this->line('');

        $backupsDir = $this->getBackupsDirectory();
        if (! File::exists($backupsDir)) {
            File::makeDirectory($backupsDir, 0755, true);
            $this->info("Created backups directory: {$backupsDir}");
        }

        try {
            $config = ConfigService::load($serverName, base_path(), $this->output);
            $cmdService = new CommandService($config, $this->output);
            $database = new DatabaseAction($cmdService, $config);

            // If --backup flag is passed, create a new backup first
            if ($this->option('backup')) {
                $this->info('Creating backup on server...');
                $remoteFile = $database->backup();
            } else {
                // Otherwise, select from existing backups
                $remoteFile = $this->selectRemoteBackup($database);
                if (! $remoteFile) {
                    return self::FAILURE;
                }
            }

            $localFile = $this->downloadRemoteBackup($remoteFile, $config);
            if (! $localFile) {
                return self::FAILURE;
            }

            $this->line('');
            $this->info('To install: php artisan deployer:db install --latest');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Database download failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Select a backup from the remote server
     */
    protected function selectRemoteBackup(DatabaseAction $database): ?string
    {
        // If --latest flag, get the latest backup
        if ($this->option('latest')) {
            $latest = $database->getLatestRemoteBackup();
            if (! $latest) {
                $this->error('No backups found on server.');
                $this->line('');
                $this->info('Create one first: php artisan deployer:db backup '.$this->argument('target'));
                $this->info('Or use --backup flag: php artisan deployer:db download '.$this->argument('target').' --backup');

                return null;
            }
            $this->info("Using latest backup: {$latest['name']} ({$latest['size']})");

            return $latest['path'];
        }

        // List available backups
        $this->info('Fetching available backups...');
        $backups = $database->listRemoteBackups();

        if (empty($backups)) {
            $this->error('No backups found on server.');
            $this->line('');
            $this->info('Create one first: php artisan deployer:db backup '.$this->argument('target'));
            $this->info('Or use --backup flag: php artisan deployer:db download '.$this->argument('target').' --backup');

            return null;
        }

        $this->line('');
        $this->info('Available backups:');
        $this->line('');

        $choices = [];
        foreach ($backups as $index => $backup) {
            $num = $index + 1;
            $this->line("  [{$num}] {$backup['name']} ({$backup['size']}) - {$backup['date']}");
            $choices[$num] = $backup;
        }

        $this->line('');
        $selection = $this->ask('Select backup number (or press Enter for latest)', '1');

        $selectedIndex = (int) $selection;
        if (! isset($choices[$selectedIndex])) {
            $this->error('Invalid selection.');

            return null;
        }

        $selected = $choices[$selectedIndex];
        $this->info("Selected: {$selected['name']}");

        return $selected['path'];
    }

    // =========================================================================
    // UPLOAD - Upload backup to remote server
    // =========================================================================

    protected function handleUpload(): int
    {
        $this->info('Database Upload');
        $this->line('');

        if (! $this->loadAvailableBackups()) {
            return self::FAILURE;
        }

        $selectedBackup = $this->argument('target')
            ? $this->resolveBackupArgument($this->argument('target'))
            : $this->selectBackup();

        if (! $selectedBackup) {
            return self::SUCCESS;
        }

        $uploadConfig = $this->getUploadConfig();
        if (! $uploadConfig) {
            return self::FAILURE;
        }

        $backupInfo = $this->getBackupInfo($selectedBackup);
        $this->info("Selected backup: {$backupInfo['name']} ({$backupInfo['size_formatted']})");
        $this->line('');
        $this->info('Upload configuration:');
        $this->line("   Target: {$uploadConfig['target']}");
        $this->line("   SSH Key: {$uploadConfig['key']}");
        $this->line("   Remote Path: {$uploadConfig['path']}");
        $this->line('');

        if (! $this->confirm('Upload this backup to the remote server?', true)) {
            $this->info('Upload cancelled.');

            return self::SUCCESS;
        }

        return $this->executeUpload($selectedBackup, $uploadConfig);
    }

    protected function getUploadConfig(): ?array
    {
        $config = [
            'target' => $this->option('target-server') ?: $this->ask('Enter remote server target (user@host)'),
            'key' => $this->option('key'),
            'path' => $this->option('path') ?: '/home/ubuntu/',
        ];

        if (! $config['target']) {
            $this->error('Remote server target is required.');

            return null;
        }

        if (! $config['key']) {
            $defaultKey = $_SERVER['HOME'].'/.ssh/id_rsa';
            $config['key'] = $this->ask('Enter SSH key path', $defaultKey);
        }

        if (! $config['key'] || ! File::exists($config['key'])) {
            $this->error('SSH key not found: '.($config['key'] ?? 'not provided'));

            return null;
        }

        $config['path'] = rtrim($config['path'], '/').'/';

        return $config;
    }

    protected function executeUpload(string $selectedBackup, array $uploadConfig): int
    {
        $backupInfo = $this->getBackupInfo($selectedBackup);
        $backupSizeMb = round($backupInfo['size'] / 1024 / 1024, 2);
        $remotePath = $uploadConfig['path'].$backupInfo['name'];

        $this->info('Starting upload...');
        $this->info("Backup size: {$backupSizeMb}MB");
        $this->line('');

        // Use SshService for consistent SCP options
        $sshService = SshService::fromArray([
            'host' => $this->parseHost($uploadConfig['target']),
            'user' => $this->parseUser($uploadConfig['target']),
            'identityFile' => $uploadConfig['key'],
            'strictHostKeyChecking' => false,
        ]);

        $startTime = microtime(true);

        $result = $sshService->upload($selectedBackup, $remotePath);

        if (! $result->successful) {
            $this->error('Upload failed: '.$result->errorOutput);

            return self::FAILURE;
        }

        $duration = round(microtime(true) - $startTime, 2);
        $speed = $duration > 0 ? round($backupSizeMb / $duration, 2) : 0;

        $this->line('');
        $this->info("Upload completed in {$duration}s ({$speed}MB/s)");
        $this->info('Database backup uploaded successfully!');

        return self::SUCCESS;
    }

    // =========================================================================
    // INSTALL - Install backup to local database
    // =========================================================================

    protected function handleInstall(): int
    {
        $this->info('Database Install');
        $this->line('');

        if (! File::exists(base_path('.env'))) {
            $this->error('No .env file found. Run from a Laravel project.');

            return self::FAILURE;
        }

        if (! $this->loadAvailableBackups()) {
            return self::FAILURE;
        }

        $selectedBackup = $this->argument('target')
            ? $this->resolveBackupArgument($this->argument('target'))
            : $this->selectBackup();

        if (! $selectedBackup) {
            return self::SUCCESS;
        }

        return $this->installLocalBackup($selectedBackup);
    }

    /**
     * Download a remote backup file to the local backups directory.
     */
    protected function downloadRemoteBackup(string $remoteFile, DeploymentConfig $config): ?string
    {
        $backupsDir = $this->getBackupsDirectory();
        if (! File::exists($backupsDir)) {
            File::makeDirectory($backupsDir, 0755, true);
        }

        $this->line('');
        $this->info('Downloading backup...');
        $localFile = $backupsDir.'/'.basename($remoteFile);

        $sshService = SshService::fromConfig($config);
        $sshService->disableMultiplexing();

        if (SshService::isWindows()) {
            $result = $sshService->download($remoteFile, $localFile);

            if (! $result->successful) {
                $this->error('Download failed: '.$result->errorOutput);

                return null;
            }
        } else {
            $sshOptions = $sshService->buildRsyncSshOptions().' -o Compression=no';
            $source = escapeshellarg("{$config->remoteUser}@{$config->hostname}:{$remoteFile}");
            $dest = escapeshellarg($localFile);
            $rsyncCommand = "rsync -hP -e '{$sshOptions}' {$source} {$dest}";

            $result = Process::timeout(3600)
                ->path(base_path())
                ->run($rsyncCommand, function ($type, $buffer) {
                    echo $buffer;
                });

            if (! $result->successful()) {
                $this->error('Download failed: '.$result->errorOutput());

                return null;
            }
        }

        $this->line('');
        $this->info('Database download completed successfully!');
        $this->info("Downloaded to: {$localFile}");

        return $localFile;
    }

    /**
     * Install a local backup file into the local database.
     */
    protected function installLocalBackup(string $backupFile): int
    {
        $dbConfig = $this->getDatabaseConfig();
        if (! $dbConfig) {
            return self::FAILURE;
        }

        $backupInfo = $this->getBackupInfo($backupFile);
        $this->line('');
        $this->info("Selected backup: {$backupInfo['name']}");
        $this->line('');
        $this->info('Database configuration:');
        $this->line("   Host: {$dbConfig['host']}:{$dbConfig['port']}");
        $this->line("   Database: {$dbConfig['database']}");
        $this->line("   Username: {$dbConfig['username']}");
        $this->line('');

        $this->warn("This will COMPLETELY REPLACE all data in database '{$dbConfig['database']}'!");

        if (! $this->option('force') && ! $this->confirm('Are you sure you want to continue?', false)) {
            $this->info('Installation cancelled.');

            return self::SUCCESS;
        }

        if (! $this->executeRestore($backupFile, $dbConfig)) {
            return self::FAILURE;
        }

        if (! $this->option('no-migrate')) {
            $this->runMigrations();
        }

        $this->offerPasswordReset();

        $this->line('');
        $this->info('Database install completed!');

        return self::SUCCESS;
    }

    protected function getDatabaseConfig(): ?array
    {
        $connection = config('database.default', 'mysql');

        if ($connection !== 'mysql') {
            $this->error("Only MySQL databases are supported. Current: {$connection}");

            return null;
        }

        $config = [
            'host' => config('database.connections.mysql.host', '127.0.0.1'),
            'port' => config('database.connections.mysql.port', '3306'),
            'database' => config('database.connections.mysql.database'),
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
        ];

        if (empty($config['database']) || empty($config['username'])) {
            $this->error('DB_DATABASE and DB_USERNAME must be set in .env');

            return null;
        }

        return $config;
    }

    protected function executeRestore(string $selectedBackup, array $dbConfig): bool
    {
        $this->info('Testing database connection...');
        $tempConfig = $this->createTempMysqlConfig($dbConfig);

        try {
            $testCmd = sprintf(
                'mysql --defaults-file=%s -e "SELECT 1;" %s',
                escapeshellarg($tempConfig),
                escapeshellarg($dbConfig['database'])
            );

            if (! Process::run($testCmd)->successful()) {
                $this->error('Cannot connect to database. Check .env configuration.');

                return false;
            }

            $this->info('Database connection successful.');
            $this->line('');

            // Drop all tables first to avoid conflicts with generated columns, constraints, etc.
            $this->info('Dropping existing tables...');
            $dropResult = Process::run('php artisan db:wipe --force');
            if (! $dropResult->successful()) {
                $this->error('Failed to drop tables: '.$dropResult->errorOutput());

                return false;
            }
            $this->info('Tables dropped.');
            $this->line('');

            $this->info('Starting restoration...');

            $startTime = time();

            // If restore fails with "generated column" error (e.g., notifications.format_indexed),
            // use this command instead to skip INSERT statements for problematic tables:
            // 'gunzip -c %s | sed "/^INSERT INTO \`notifications\`/d" | mysql --defaults-file=%s %s'
            $command = sprintf(
                'gunzip -c %s | mysql --defaults-file=%s %s',
                escapeshellarg($selectedBackup),
                escapeshellarg($tempConfig),
                escapeshellarg($dbConfig['database'])
            );

            $result = Process::timeout(3600)->run($command);

            if (! $result->successful()) {
                $this->error('Restoration failed: '.$result->errorOutput());

                return false;
            }

            $duration = time() - $startTime;
            $this->info("Restoration completed in {$duration}s");

            return true;
        } finally {
            if (File::exists($tempConfig)) {
                File::delete($tempConfig);
            }
        }
    }

    protected function createTempMysqlConfig(array $dbConfig): string
    {
        $tempConfig = tempnam(sys_get_temp_dir(), 'mysql_');

        File::put($tempConfig, sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n",
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['username'],
            $dbConfig['password']
        ));

        return $tempConfig;
    }

    protected function runMigrations(): void
    {
        $this->line('');

        if (! $this->confirm('Run migrations to ensure schema is up to date?', true)) {
            return;
        }

        $this->info('Running migrations...');
        $result = Process::run('php artisan migrate --force');

        if ($result->successful()) {
            $this->info('Migrations completed.');
        } else {
            $this->error('Migrations failed: '.$result->errorOutput());
        }
    }

    protected function offerPasswordReset(): void
    {
        if (config('app.env') === 'production') {
            return;
        }

        try {
            $usersCount = User::count();
            if ($usersCount === 0) {
                return;
            }

            $this->line('');
            $this->info("Detected {$usersCount} user(s) in the database.");

            if (! $this->confirm('Reset a user password for testing?', true)) {
                return;
            }

            $firstUser = User::orderBy('created_at')->first();
            $email = $this->ask('Enter user email', $firstUser?->email);

            $user = User::where('email', $email)->first();
            if (! $user) {
                $this->error("User '{$email}' not found.");

                return;
            }

            $password = $this->ask('Enter new password');
            $user->password = bcrypt($password);
            $user->save();

            $this->info("Password for '{$email}' reset to: {$password}");
        } catch (\Exception $e) {
            // Silently skip if users table doesn't exist
        }
    }

    // =========================================================================
    // LIST - List available local backups
    // =========================================================================

    protected function handleList(): int
    {
        if (! $this->loadAvailableBackups()) {
            return self::FAILURE;
        }

        $this->displayBackups();

        return self::SUCCESS;
    }

    // =========================================================================
    // USAGE
    // =========================================================================

    private function parseUser(string $target): string
    {
        return str_contains($target, '@') ? explode('@', $target, 2)[0] : 'root';
    }

    private function parseHost(string $target): string
    {
        return str_contains($target, '@') ? explode('@', $target, 2)[1] : $target;
    }

    protected function showUsage(): int
    {
        $this->error('Invalid action. Available actions:');
        $this->line('');
        $this->line('  php artisan deployer:db backup {server}     Create backup on remote server');
        $this->line('  php artisan deployer:db download {server}   Download existing backup from server');
        $this->line('  php artisan deployer:db upload              Upload backup to remote server');
        $this->line('  php artisan deployer:db install             Install backup to local database');
        $this->line('  php artisan deployer:db list                List available local backups');
        $this->line('');
        $this->line('Examples:');
        $this->line('  php artisan deployer:db backup staging');
        $this->line('  php artisan deployer:db backup production --download           # Backup + download');
        $this->line('  php artisan deployer:db backup production --install            # Backup + download + install');
        $this->line('  php artisan deployer:db backup production --install --force    # Same, skip confirmation');
        $this->line('  php artisan deployer:db download production                    # Select from existing backups');
        $this->line('  php artisan deployer:db download production --latest           # Download latest backup');
        $this->line('  php artisan deployer:db download production --backup           # Create new backup & download');
        $this->line('  php artisan deployer:db upload --latest --target-server=user@host');
        $this->line('  php artisan deployer:db install --latest');

        return self::FAILURE;
    }
}
