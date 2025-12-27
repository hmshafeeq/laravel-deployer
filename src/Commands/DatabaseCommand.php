<?php

namespace Shaf\LaravelDeployer\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Shaf\LaravelDeployer\Actions\DatabaseAction;
use Shaf\LaravelDeployer\Concerns\ManagesLocalBackups;
use Shaf\LaravelDeployer\Concerns\SelectsServer;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;

class DatabaseCommand extends Command
{
    use ManagesLocalBackups, SelectsServer;

    protected $signature = 'db
                            {action : Action to perform (backup, download, upload, restore, list)}
                            {target? : Server name for backup/download, or backup file for restore}
                            {--select : Show available servers and select interactively}
                            {--latest : Use the latest backup}
                            {--target-server= : Remote server for upload (user@host)}
                            {--key= : SSH key path for upload}
                            {--path= : Remote destination path for upload}
                            {--no-migrate : Skip running migrations after restore}';

    protected $description = 'Database operations: backup, download, upload, restore';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'backup' => $this->handleBackup(),
            'download' => $this->handleDownload(),
            'upload' => $this->handleUpload(),
            'restore' => $this->handleRestore(),
            'list' => $this->handleList(),
            default => $this->showUsage(),
        };
    }

    // =========================================================================
    // BACKUP - Create backup on remote server
    // =========================================================================

    protected function handleBackup(): int
    {
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
            $this->line('');
            $this->info('To download: php artisan db download '.$serverName);

            return self::SUCCESS;
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

            $this->info('Creating backup on server...');
            $remoteFile = $database->backup();

            $this->line('');
            $this->info('Downloading backup...');
            $localFile = $backupsDir.'/'.basename($remoteFile);
            $database->download($remoteFile, $localFile);

            $this->line('');
            $this->info('Database download completed successfully!');
            $this->info("Downloaded to: {$localFile}");
            $this->line('');
            $this->info('To restore: php artisan db restore');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Database download failed: '.$e->getMessage());

            return self::FAILURE;
        }
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

        $scpCommand = sprintf(
            'scp -o ServerAliveInterval=30 -o ServerAliveCountMax=10 -i %s %s %s:%s',
            escapeshellarg($uploadConfig['key']),
            escapeshellarg($selectedBackup),
            escapeshellarg($uploadConfig['target']),
            escapeshellarg($remotePath)
        );

        $startTime = microtime(true);

        $result = Process::timeout(3600)
            ->path(base_path())
            ->run($scpCommand, function ($type, $buffer) {
                echo $buffer;
            });

        if (! $result->successful()) {
            $this->error('Upload failed: '.$result->errorOutput());

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
    // RESTORE - Restore backup locally
    // =========================================================================

    protected function handleRestore(): int
    {
        $this->info('Database Restore');
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

        $dbConfig = $this->getDatabaseConfig();
        if (! $dbConfig) {
            return self::FAILURE;
        }

        $backupInfo = $this->getBackupInfo($selectedBackup);
        $this->info("Selected backup: {$backupInfo['name']}");
        $this->line('');
        $this->info('Database configuration:');
        $this->line("   Host: {$dbConfig['host']}:{$dbConfig['port']}");
        $this->line("   Database: {$dbConfig['database']}");
        $this->line("   Username: {$dbConfig['username']}");
        $this->line('');

        $this->warn("This will COMPLETELY REPLACE all data in database '{$dbConfig['database']}'!");

        if (! $this->confirm('Are you sure you want to continue?', false)) {
            $this->info('Restoration cancelled.');

            return self::SUCCESS;
        }

        if (! $this->executeRestore($selectedBackup, $dbConfig)) {
            return self::FAILURE;
        }

        if (! $this->option('no-migrate')) {
            $this->runMigrations();
        }

        $this->offerPasswordReset();

        $this->line('');
        $this->info('Database restore completed!');

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
            $this->info('Starting restoration...');

            $startTime = time();
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

            $password = $this->ask('Enter new password', 'admin@123');
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

    protected function showUsage(): int
    {
        $this->error('Invalid action. Available actions:');
        $this->line('');
        $this->line('  php artisan db backup {server}     Create backup on remote server');
        $this->line('  php artisan db download {server}   Download backup from remote server');
        $this->line('  php artisan db upload              Upload backup to remote server');
        $this->line('  php artisan db restore             Restore backup locally');
        $this->line('  php artisan db list                List available local backups');
        $this->line('');
        $this->line('Examples:');
        $this->line('  php artisan db backup staging');
        $this->line('  php artisan db download production');
        $this->line('  php artisan db upload --latest --target-server=user@host');
        $this->line('  php artisan db restore --latest');

        return self::FAILURE;
    }
}
