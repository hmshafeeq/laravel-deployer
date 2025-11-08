<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Deployer;

class DatabaseService
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
    }

    /**
     * Get database configuration with temporary config file
     */
    public function getDatabaseConfigWithFile(): array
    {
        $currentPath = $this->deployer->getCurrentPath();

        $this->deployer->writeln("🔍 Getting database configuration...");

        $this->deployer->writeln("run cd {$currentPath} && php artisan tinker --execute=\"echo config('database.default');\"");
        $connection = trim($this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.default');\""));
        $this->deployer->writeln($connection);

        $this->deployer->writeln("run cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.host');\"");
        $host = trim($this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.host');\""));
        $this->deployer->writeln($host);

        $this->deployer->writeln("run cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.database');\"");
        $database = trim($this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.database');\""));
        $this->deployer->writeln($database);

        $this->deployer->writeln("run cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.username');\"");
        $username = trim($this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.username');\""));
        $this->deployer->writeln($username);

        $this->deployer->writeln("run cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.password');\"");
        $password = trim($this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.password');\""));
        $this->deployer->writeln($password);

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
        $this->deployer->writeln("run echo '[client]' > {$configFile}");
        $this->deployer->run("echo '[client]' > {$configFile}");

        $this->deployer->writeln("run echo 'host={$host}' >> {$configFile}");
        $this->deployer->run("echo 'host={$host}' >> {$configFile}");

        $this->deployer->writeln("run echo 'user={$username}' >> {$configFile}");
        $this->deployer->run("echo 'user={$username}' >> {$configFile}");

        $this->deployer->writeln("run echo 'password={$password}' >> {$configFile}");
        $this->deployer->run("echo 'password={$password}' >> {$configFile}");

        return [
            'host' => $host,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'config_file' => $configFile,
        ];
    }

    /**
     * Get information about a remote file
     */
    public function getRemoteFileInfo(string $filePath): array
    {
        $sizeBytes = (int) trim($this->deployer->run("stat -c%s {$filePath}"));
        $sizeHuman = trim($this->deployer->run("ls -lh {$filePath} | awk '{print \$5}'"));

        $fileCheck = trim($this->deployer->run("test -r {$filePath} && echo 'OK' || echo 'FAIL'"));
        if ($fileCheck !== 'OK') {
            throw new \RuntimeException("Cannot access backup file on remote server: {$filePath}");
        }

        return ['bytes' => $sizeBytes, 'human' => $sizeHuman];
    }

    /**
     * Select a backup file from available backups
     */
    public function selectBackup(?string $selection = null): array
    {
        $deployPath = $this->deployer->getDeployPath();

        $backupList = $this->deployer->run("ls -lt {$deployPath}/shared/backups/db_backup_*.sql.gz 2>/dev/null || echo \"\"");
        if (empty($backupList)) {
            throw new \RuntimeException('No database backups found on server');
        }

        $backups = $this->deployer->run("ls -lht {$deployPath}/shared/backups/db_backup_*.sql.gz | head -10");
        $lines = array_filter(array_map('trim', explode("\n", trim($backups))));

        // If selection argument provided, don't show interactive list
        if ($selection !== null) {
            if (strtolower($selection) === 'latest') {
                $choiceIndex = 0;
                if (isset($lines[0])) {
                    $parts = preg_split('/\s+/', $lines[0]);
                    $filename = basename($parts[8]);
                    $this->deployer->writeln("📋 Using latest backup: {$filename}");
                }
            } elseif (is_numeric($selection)) {
                $choiceIndex = (int) $selection - 1;
                $this->deployer->writeln("📋 Using backup #{$selection}: " . basename($lines[$choiceIndex]));
            } else {
                throw new \RuntimeException("Invalid backup selection: {$selection}");
            }
        } else {
            // Interactive selection
            $this->deployer->writeln("📋 Available database backups:");
            $this->deployer->writeln("");
            foreach ($lines as $index => $line) {
                $parts = preg_split('/\s+/', $line);
                $size = $parts[4];
                $filename = basename($parts[8]);
                $this->deployer->writeln("   " . ($index + 1) . ". {$filename} ({$size})");
            }
            $this->deployer->writeln("");

            // For automated selection, default to latest
            $choiceIndex = 0;
            $this->deployer->writeln("Using latest backup (automatic selection)");
        }

        if ($choiceIndex < 0 || $choiceIndex >= count($lines)) {
            throw new \RuntimeException('Invalid backup selection');
        }

        $parts = preg_split('/\s+/', $lines[$choiceIndex]);

        return [
            'path' => $parts[8],
            'name' => basename($parts[8]),
            'size' => $parts[4],
        ];
    }

    /**
     * Verify downloaded file
     */
    public function verifyDownload(string $localFile, int $remoteSizeBytes, float $downloadTime): void
    {
        if (!file_exists($localFile)) {
            throw new \RuntimeException('Download failed: Local file not found');
        }

        $localSize = filesize($localFile);
        $sizeDiff = abs($localSize - $remoteSizeBytes);
        $tolerance = max(1024, $remoteSizeBytes * 0.001);

        if ($sizeDiff > $tolerance) {
            throw new \RuntimeException("File size mismatch (local: {$localSize}, remote: {$remoteSizeBytes}, diff: {$sizeDiff})");
        }

        $localSizeHuman = $this->deployer->runLocally("ls -lh '{$localFile}' | awk '{print \$5}'");
        $speedMBps = round(($localSize / 1024 / 1024) / $downloadTime, 2);

        $this->deployer->writeln("");
        $this->deployer->writeln("✅ Database backup downloaded successfully!");
        $this->deployer->writeln("📁 Location: {$localFile}");
        $this->deployer->writeln("📊 Size: {$localSizeHuman}");
        $this->deployer->writeln("⏱️  Time: {$downloadTime}s");
        $this->deployer->writeln("🚀 Speed: {$speedMBps} MB/s");
    }
}
