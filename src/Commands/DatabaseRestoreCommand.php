<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DatabaseRestoreCommand extends Command
{
    protected $signature = 'database:restore 
                            {backup? : The backup file to restore (optional - will prompt if not provided)}
                            {--list : List available backups without restoring}
                            {--latest : Restore the latest backup without prompting}
                            {--no-migrate : Skip running migrations after restore}';

    protected $description = 'Restore database from backup file';

    protected array $backups = [];

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
        $backupsDir = base_path('backups');
        if (!File::exists($backupsDir)) {
            $this->error('❌ No backups directory found. Please run \'php vendor/bin/dep database:download\' first.');
            return self::FAILURE;
        }

        // Get available backups
        $this->backups = $this->getAvailableBackups($backupsDir);

        if (empty($this->backups)) {
            $this->error('❌ No database backups found in ./backups/ directory.');
            $this->info('ℹ️  Run \'php vendor/bin/dep database:download\' to download backups from server.');
            return self::FAILURE;
        }

        // Handle --list option
        if ($this->option('list')) {
            $this->displayBackups();
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

        $this->line('');
        $this->info('✅ Database restore process completed!');

        return self::SUCCESS;
    }

    protected function getAvailableBackups(string $backupsDir): array
    {
        $files = File::glob($backupsDir . '/db_backup_*.sql.gz');
        
        // Sort by modification time (newest first)
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $files;
    }

    protected function displayBackups(): void
    {
        $this->info('📋 Available database backups:');
        $this->line('');

        foreach ($this->backups as $index => $backup) {
            $name = basename($backup);
            $size = $this->formatFileSize(filesize($backup));
            $date = date('Y-m-d H:i:s', filemtime($backup));
            
            $this->line(sprintf('   %d. %s (%s) - %s', $index + 1, $name, $size, $date));
        }

        $this->line('');
    }

    protected function getSelectedBackup(): ?string
    {
        // Handle --latest option
        if ($this->option('latest')) {
            return $this->backups[0];
        }

        // Handle backup argument
        $backupArg = $this->argument('backup');
        if ($backupArg) {
            // If it's a number, treat as index
            if (is_numeric($backupArg)) {
                $index = (int) $backupArg - 1;
                if (isset($this->backups[$index])) {
                    return $this->backups[$index];
                }
                $this->error("❌ Invalid backup selection: {$backupArg}");
                return null;
            }
            
            // Treat as filename
            $backupPath = base_path('backups/' . $backupArg);
            if (File::exists($backupPath)) {
                return $backupPath;
            }
            
            $this->error("❌ Backup file not found: {$backupArg}");
            return null;
        }

        // Interactive selection
        $this->displayBackups();
        
        $choice = $this->ask(
            "Enter backup number to restore (1-{$this->count()}) or press Enter for latest",
            '1'
        );

        if (!is_numeric($choice) || $choice < 1 || $choice > count($this->backups)) {
            $this->error("❌ Invalid backup selection: {$choice}");
            return null;
        }

        return $this->backups[$choice - 1];
    }

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
            $this->info("✅ Database restoration completed successfully!");
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

    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . $units[$unitIndex];
    }

    protected function count(): int
    {
        return count($this->backups);
    }
}