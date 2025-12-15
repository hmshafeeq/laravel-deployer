<?php

namespace Shaf\LaravelDeployer\Commands;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Shaf\LaravelDeployer\Commands\Traits\ManagesBackupSelection;

class DatabaseRestoreCommand extends BaseDeployerCommand
{
    use ManagesBackupSelection;

    protected $signature = 'database:restore
                            {backup? : The backup file to restore (optional - will prompt if not provided)}
                            {--list : List available backups without restoring}
                            {--latest : Restore the latest backup without prompting}
                            {--no-migrate : Skip running migrations after restore}';

    protected $description = 'Restore database from backup file';

    public function handle(): int
    {
        $this->info('🗄️  Database Restore');
        $this->line('');

        // Check if we're in a Laravel project
        if (!File::exists(base_path('.env'))) {
            $this->error('❌ No .env file found. Please ensure this command is run from a Laravel project.');

            return self::FAILURE;
        }

        // Check if backups directory exists
        if (!$this->getBackupManager()->backupsDirectoryExists()) {
            $this->displayNoBackupsError();

            return self::FAILURE;
        }

        $backups = $this->getBackupManager()->getAvailableBackups();
        if (empty($backups)) {
            $this->displayNoBackupsError();

            return self::FAILURE;
        }

        // Handle --list option
        if ($this->option('list')) {
            $this->displayBackups($backups);

            return self::SUCCESS;
        }

        // Get selected backup
        $selectedBackup = $this->getSelectedBackup();
        if (!$selectedBackup) {
            $this->info('ℹ️  Restoration cancelled.');

            return self::SUCCESS;
        }

        // Get database configuration
        $dbConfig = $this->getDatabaseConfig();
        if (!$dbConfig) {
            return self::FAILURE;
        }

        // Confirm restoration
        if (!$this->confirmRestore($selectedBackup, $dbConfig)) {
            $this->info('ℹ️  Restoration cancelled.');

            return self::SUCCESS;
        }

        // Perform restoration
        if (!$this->restoreDatabase($selectedBackup, $dbConfig)) {
            return self::FAILURE;
        }

        // Run migrations if not skipped
        if (!$this->option('no-migrate')) {
            $this->runMigrations();
        }

        // Offer to reset user password
        $this->offerPasswordReset();

        $this->line('');
        $this->info('✅ Database restore process completed!');

        return self::SUCCESS;
    }

    /**
     * Get database configuration from Laravel config
     *
     * @return array|null Database configuration array or null on error
     */
    protected function getDatabaseConfig(): ?array
    {
        try {
            $config = [
                'connection' => config('database.default', 'mysql'),
                'host' => config('database.connections.mysql.host', '127.0.0.1'),
                'port' => config('database.connections.mysql.port', '3306'),
                'database' => config('database.connections.mysql.database'),
                'username' => config('database.connections.mysql.username'),
                'password' => config('database.connections.mysql.password'),
            ];

            // Validate required settings
            if (empty($config['database'])) {
                $this->error('❌ DB_DATABASE is not set in .env file');

                return null;
            }

            if (empty($config['username'])) {
                $this->error('❌ DB_USERNAME is not set in .env file');

                return null;
            }

            // Only support MySQL for now
            if ($config['connection'] !== 'mysql') {
                $this->error("❌ Only MySQL databases are supported. Current connection: {$config['connection']}");

                return null;
            }

            return $config;
        } catch (\Exception $e) {
            $this->error("❌ Error reading database configuration: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Confirm database restoration with user
     *
     * @param string $selectedBackup Path to backup file
     * @param array $dbConfig Database configuration
     * @return bool True if user confirms, false otherwise
     */
    protected function confirmRestore(string $selectedBackup, array $dbConfig): bool
    {
        $backupName = basename($selectedBackup);

        $this->info("📋 Selected backup: {$backupName}");
        $this->line('');
        $this->info('🗄️  Database configuration:');
        $this->line("   Host: {$dbConfig['host']}:{$dbConfig['port']}");
        $this->line("   Database: {$dbConfig['database']}");
        $this->line("   Username: {$dbConfig['username']}");
        $this->line('');

        $this->warn("⚠️  This will COMPLETELY REPLACE all data in database '{$dbConfig['database']}'!");

        return $this->confirm('Are you sure you want to continue?', false);
    }

    /**
     * Restore database from backup file
     *
     * @param string $selectedBackup Path to backup file
     * @param array $dbConfig Database configuration
     * @return bool True on success, false on failure
     */
    protected function restoreDatabase(string $selectedBackup, array $dbConfig): bool
    {
        $backupName = basename($selectedBackup);
        $backupSizeMb = round(filesize($selectedBackup) / 1024 / 1024, 2);

        // Test database connection first
        $this->info('🔌 Testing database connection...');
        if (!$this->testDatabaseConnection($dbConfig)) {
            $this->error('❌ Cannot connect to database. Please check your .env configuration.');

            return false;
        }

        $this->info('✅ Database connection successful.');
        $this->line('');

        $this->info('🚀 Starting database restoration...');
        $this->info("📊 Backup size: {$backupSizeMb}MB");

        $startTime = time();

        // Create temporary MySQL config file
        $tempConfig = $this->createTempMysqlConfig($dbConfig);

        try {
            // Run restoration command
            $command = sprintf(
                'gunzip -c %s | mysql --defaults-file=%s %s',
                escapeshellarg($selectedBackup),
                escapeshellarg($tempConfig),
                escapeshellarg($dbConfig['database'])
            );

            $result = Process::timeout(3600)->run($command); // 1 hour timeout

            if (!$result->successful()) {
                $this->error('❌ Database restoration failed:');
                $this->line($result->errorOutput());

                return false;
            }

            $duration = time() - $startTime;
            $this->info('✅ Database restoration completed successfully!');
            $this->info("⏱️  Restoration time: {$duration} seconds");
            $this->info("🗄️  Database '{$dbConfig['database']}' has been restored from backup: {$backupName}");

            return true;
        } finally {
            // Clean up temp config
            if (File::exists($tempConfig)) {
                File::delete($tempConfig);
            }
        }
    }

    /**
     * Test database connection
     *
     * @param array $dbConfig Database configuration
     * @return bool True if connection successful, false otherwise
     */
    protected function testDatabaseConnection(array $dbConfig): bool
    {
        $tempConfig = $this->createTempMysqlConfig($dbConfig);

        try {
            $command = sprintf(
                'mysql --defaults-file=%s -e "SELECT 1;" %s',
                escapeshellarg($tempConfig),
                escapeshellarg($dbConfig['database'])
            );

            $result = Process::run($command);

            return $result->successful();
        } finally {
            if (File::exists($tempConfig)) {
                File::delete($tempConfig);
            }
        }
    }

    /**
     * Create temporary MySQL configuration file
     *
     * @param array $dbConfig Database configuration
     * @return string Path to temporary configuration file
     */
    protected function createTempMysqlConfig(array $dbConfig): string
    {
        $tempConfig = tempnam(sys_get_temp_dir(), 'mysql_restore_');

        $content = sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n",
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['username'],
            $dbConfig['password']
        );

        File::put($tempConfig, $content);

        return $tempConfig;
    }

    /**
     * Run Laravel migrations
     *
     * @return void
     */
    protected function runMigrations(): void
    {
        $this->line('');

        if (!$this->confirm('Run \'php artisan migrate\' to ensure database schema is up to date?', true)) {
            return;
        }

        $this->info('🔄 Running migrations...');

        $result = Process::run('php artisan migrate --force');

        if ($result->successful()) {
            $this->info('✅ Migrations completed.');
        } else {
            $this->error('❌ Migrations failed:');
            $this->line($result->errorOutput());
        }
    }

    /**
     * Offer to reset a user password
     *
     * @return void
     */
    protected function offerPasswordReset(): void
    {
        // Only offer in non-production environments
        if (config('app.env') === 'production') {
            return;
        }

        $this->line('');

        // Check if users table exists and has data
        try {
            $usersCount = User::count();

            if ($usersCount === 0) {
                $this->warn('⚠️  No users found in the database.');

                return;
            }

            $this->info("👥 Detected {$usersCount} user(s) in the database.");

            if (!$this->confirm('Would you like to reset a user password for testing?', true)) {
                return;
            }

            // Get first user as default
            $firstUser = User::orderBy('created_at')->first();
            $defaultEmail = $firstUser ? $firstUser->email : '';

            $email = $this->ask('Enter user email', $defaultEmail);

            if (!$email) {
                $this->warn('⚠️  No email provided. Skipping password reset.');

                return;
            }

            $user = User::where('email', $email)->first();

            if (!$user) {
                $this->error("❌ User with email '{$email}' not found.");

                return;
            }

            $password = $this->ask('Enter new password', 'admin@123');

            $user->password = bcrypt($password);
            $user->save();

            $this->line('');
            $this->info("✅ Password for user '{$user->email}' has been reset successfully.");
            $this->info("   New password: {$password}");
        } catch (\Exception $e) {
            // Silently fail if users table doesn't exist or there's any error
            // This is optional functionality and shouldn't break the restore process
            $this->line('');
            $this->comment('ℹ️  Unable to access users table. Skipping password reset option.');
        }
    }
}
