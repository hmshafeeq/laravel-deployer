<?php

namespace Shaf\LaravelDeployer\Actions\Database;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;

class BackupDatabaseAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config
    ) {
    }

    public function execute(): void
    {
        $timestamp = date('Y-m-d_H-i-s');
        $deployPath = $this->config->deployPath;
        $backupFile = "{$deployPath}/shared/backups/db_backup_{$timestamp}.sql.gz";

        $this->output->command("mkdir -p {$deployPath}/shared/backups");
        $this->executor->execute("mkdir -p {$deployPath}/shared/backups");

        $dbConfig = $this->getDatabaseConfig();

        try {
            $this->output->info("💾 Starting database backup...");
            $this->output->info("📊 Database: {$dbConfig['database']}");
            $this->output->info("🏠 Host: {$dbConfig['host']}");
            $this->output->newLine();
            $this->output->info("⏳ This may take a while for large databases...");

            // Run mysqldump with timeout and proper error handling
            $dumpCommand = "timeout 1800 mysqldump --defaults-file={$dbConfig['config_file']} --single-transaction --routines --triggers {$dbConfig['database']} 2>&1";
            $compressCommand = "gzip -8 > {$backupFile}";

            $this->output->command("{$dumpCommand} | {$compressCommand}; echo \$?");
            $result = $this->executor->execute("{$dumpCommand} | {$compressCommand}; echo \$?");
            $exitCode = (int) trim($result);

            if ($exitCode !== 0) {
                throw new \RuntimeException("mysqldump failed with exit code: {$exitCode}");
            }

            // Verify backup file was created and has content
            $this->verifyBackupFile($backupFile);

            $this->output->newLine();
            $this->output->success("Database backup completed successfully!");

            $fileSizeHuman = trim($this->executor->execute("ls -lh {$backupFile} | awk '{print \$5}'"));
            $this->output->info("📁 Location: {$backupFile}");
            $this->output->info("📊 Size: {$fileSizeHuman}");

            // Clean up old backups
            $this->cleanupOldBackups($deployPath);

        } catch (\Exception $e) {
            $this->output->newLine();
            $this->output->error("Backup failed: " . $e->getMessage());

            // Clean up failed backup file if it exists
            $fileExists = trim($this->executor->execute("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
            if ($fileExists === 'OK') {
                $this->executor->execute("rm -f {$backupFile}");
                $this->output->info("🧹 Cleaned up failed backup file");
            }

            throw $e;
        } finally {
            // Always clean up config file
            $this->executor->execute("rm -f {$dbConfig['config_file']} 2>/dev/null || true");
        }
    }

    protected function getDatabaseConfig(): array
    {
        $currentPath = $this->config->deployPath . '/current';

        $this->output->info("🔍 Getting database configuration...");

        $connection = trim($this->executor->execute("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.default');\""));
        $host = trim($this->executor->execute("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.host');\""));
        $database = trim($this->executor->execute("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.database');\""));
        $username = trim($this->executor->execute("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.username');\""));
        $password = trim($this->executor->execute("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.password');\""));

        // Validate configuration
        if (empty($host) || !preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
            throw new \RuntimeException("Invalid database host: {$host}");
        }
        if (empty($database) || !preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
            throw new \RuntimeException("Invalid database name: {$database}");
        }
        if (empty($username) || !preg_match('/^[a-zA-Z0-9_@.-]+$/', $username)) {
            throw new \RuntimeException("Invalid database user: {$username}");
        }
        if (empty($password)) {
            throw new \RuntimeException('Database password cannot be empty');
        }

        $configFile = '/tmp/mysql_backup_' . uniqid() . '.cnf';
        $this->executor->execute("echo '[client]' > {$configFile}");
        $this->executor->execute("echo 'host={$host}' >> {$configFile}");
        $this->executor->execute("echo 'user={$username}' >> {$configFile}");
        $this->executor->execute("echo 'password={$password}' >> {$configFile}");

        return [
            'host' => $host,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'config_file' => $configFile,
        ];
    }

    protected function verifyBackupFile(string $backupFile): void
    {
        $fileExists = trim($this->executor->execute("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));

        if ($fileExists !== 'OK') {
            throw new \RuntimeException("Backup file was not created: {$backupFile}");
        }

        $fileSize = (int) trim($this->executor->execute("stat -c%s {$backupFile} 2>/dev/null || stat -f%z {$backupFile} 2>/dev/null || echo 0"));

        if ($fileSize < 100) {
            throw new \RuntimeException("Backup file is too small ({$fileSize} bytes), backup likely failed");
        }
    }

    protected function cleanupOldBackups(string $deployPath): void
    {
        $this->output->newLine();
        $this->output->info("🧹 Cleaning up old backups (keeping 3 most recent)...");

        $this->executor->execute("cd {$deployPath}/shared/backups && ls -t db_backup_*.sql.gz | tail -n +4 | xargs -r rm -f");

        $backupCount = (int) trim($this->executor->execute("cd {$deployPath}/shared/backups && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l"));
        $this->output->success("Total backups on server: {$backupCount}");
    }
}
