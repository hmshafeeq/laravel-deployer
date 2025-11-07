<?php

namespace Shaf\LaravelDeployer\Deployer;

class DatabaseTasks
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
    }

    protected function getDatabaseConfigWithFile(): array
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

    public function backup(): void
    {
        $this->deployer->task('database:backup', function ($deployer) {
            $timestamp = date('Y-m-d_H-i-s');
            $deployPath = $deployer->getDeployPath();
            $backupFile = "{$deployPath}/shared/backups/db_backup_{$timestamp}.sql.gz";

            $deployer->writeln("run mkdir -p {$deployPath}/shared/backups");
            $deployer->run("mkdir -p {$deployPath}/shared/backups");

            $config = $this->getDatabaseConfigWithFile();

            try {
                $deployer->writeln("💾 Starting database backup...");
                $deployer->writeln("📊 Database: {$config['database']}");
                $deployer->writeln("🏠 Host: {$config['host']}");
                $deployer->writeln("");
                $deployer->writeln("⏳ This may take a while for large databases...");

                // Run mysqldump with timeout and proper error handling
                $dumpCommand = "timeout 1800 mysqldump --defaults-file={$config['config_file']} --single-transaction --routines --triggers {$config['database']} 2>&1";
                $compressCommand = "gzip -8 > {$backupFile}";

                $deployer->writeln("run {$dumpCommand} | {$compressCommand}; echo \$?");
                $result = $deployer->run("{$dumpCommand} | {$compressCommand}; echo \$?");
                $exitCode = (int) trim($result);

                if ($exitCode !== 0) {
                    throw new \RuntimeException("mysqldump failed with exit code: {$exitCode}");
                }

                // Verify backup file was created and has content
                $deployer->writeln("run test -f {$backupFile} && echo 'OK' || echo 'FAIL'");
                $fileExists = trim($deployer->run("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
                if (!empty($fileExists)) {
                    $deployer->writeln($fileExists);
                }

                if ($fileExists !== 'OK') {
                    throw new \RuntimeException("Backup file was not created: {$backupFile}");
                }

                $deployer->writeln("run stat -c%s {$backupFile} 2>/dev/null || stat -f%z {$backupFile} 2>/dev/null || echo 0");
                $fileSize = (int) trim($deployer->run("stat -c%s {$backupFile} 2>/dev/null || stat -f%z {$backupFile} 2>/dev/null || echo 0"));
                $deployer->writeln($fileSize);

                if ($fileSize < 100) {
                    throw new \RuntimeException("Backup file is too small ({$fileSize} bytes), backup likely failed");
                }

                $deployer->writeln("");
                $deployer->writeln("✅ Database backup completed successfully!");

                $deployer->writeln("run ls -lh {$backupFile} | awk '{print \$5}'");
                $fileSizeHuman = trim($deployer->run("ls -lh {$backupFile} | awk '{print \$5}'"));
                $deployer->writeln($fileSizeHuman);

                $deployer->writeln("📁 Location: {$backupFile}");
                $deployer->writeln("📊 Size: {$fileSizeHuman}");

                // Clean up old backups (keep only 3 most recent)
                $deployer->writeln("");
                $deployer->writeln("🧹 Cleaning up old backups (keeping 3 most recent)...");

                $deployer->writeln("run cd {$deployPath}/shared/backups && ls -t db_backup_*.sql.gz | tail -n +4 | xargs -r rm -f");
                $deployer->run("cd {$deployPath}/shared/backups && ls -t db_backup_*.sql.gz | tail -n +4 | xargs -r rm -f");

                $deployer->writeln("run cd {$deployPath}/shared/backups && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l");
                $backupCount = (int) trim($deployer->run("cd {$deployPath}/shared/backups && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l"));
                $deployer->writeln($backupCount);

                $deployer->writeln("✅ Total backups on server: {$backupCount}");

            } catch (\Exception $e) {
                $deployer->writeln("");
                $deployer->writeln("❌ Backup failed: " . $e->getMessage(), 'error');

                // Clean up failed backup file if it exists
                $fileExists = trim($deployer->run("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
                if ($fileExists === 'OK') {
                    $deployer->run("rm -f {$backupFile}");
                    $deployer->writeln("🧹 Cleaned up failed backup file");
                }

                throw $e;
            } finally {
                // Always clean up config file
                $deployer->writeln("run rm -f {$config['config_file']} 2>/dev/null || true");
                $deployer->run("rm -f {$config['config_file']} 2>/dev/null || true");
            }
        });
    }

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

    public function download(?string $backupSelection = null, ?string $downloadMethod = null): void
    {
        $this->deployer->task('database:download', function ($deployer) use ($backupSelection, $downloadMethod) {
            $backup = $this->selectBackup($backupSelection);
            $remoteInfo = $this->getRemoteFileInfo($backup['path']);

            $backupDir = './.deploy/downloads/backups';
            $deployer->runLocally("mkdir -p {$backupDir}");

            $deployer->writeln("📥 Downloading {$backup['name']} ({$remoteInfo['human']})...");

            try {
                $this->downloadWithProgress($backup['path'], "{$backupDir}/{$backup['name']}", $remoteInfo['bytes'], $downloadMethod);
                $deployer->writeln("");
                $deployer->writeln("💡 To restore locally: php artisan database:restore " . $backup['name']);
            } catch (\Exception $e) {
                $localFile = "{$backupDir}/{$backup['name']}";
                if (file_exists($localFile)) {
                    $localSize = filesize($localFile);
                    if ($localSize < ($remoteInfo['bytes'] * 0.95)) {
                        unlink($localFile);
                        $deployer->writeln("🧹 Cleaned up partial download");
                    } else {
                        $deployer->writeln("📁 File appears complete, keeping download");
                    }
                }
                throw new \RuntimeException('Download failed: ' . $e->getMessage());
            }
        });
    }

    protected function downloadWithProgress(string $remoteFile, string $localFile, int $remoteSizeBytes, ?string $method = null): void
    {
        $deployUser = $this->deployer->get('remote_user');
        $deployHost = $this->deployer->get('hostname');

        if ($method === null) {
            $this->deployer->writeln("💡 Speed optimization tips:");
            $this->deployer->writeln("   • Option 1 (rsync): Best for reliability, resume capability");
            $this->deployer->writeln("   • Option 2 (scp): Often faster for large files, no resume");
            $this->deployer->writeln("");
            $method = '1'; // Default to rsync
            $this->deployer->writeln("Using rsync (default)");
        } else {
            $methodName = (strtolower($method) === 'scp' || $method === '2') ? 'SCP' : 'rsync';
            $this->deployer->writeln("⚡ Using {$methodName} download method");
        }

        if (strtolower($method) === 'rsync' || $method === '1') {
            $method = '1';
        } elseif (strtolower($method) === 'scp' || $method === '2') {
            $method = '2';
        } else {
            $method = '1';
        }

        $startTime = microtime(true);

        if ($method === '2') {
            $this->deployer->writeln("🚀 Using SCP for maximum speed...");
            $cmd = "scp -o Compression=no -o TCPKeepAlive=yes -o ServerAliveInterval=60 {$deployUser}@{$deployHost}:{$remoteFile} " . dirname($localFile) . '/';
        } else {
            $this->deployer->writeln("⚡ Using optimized rsync (no compression, no bandwidth limit)...");
            $cmd = "rsync -av --partial --inplace {$deployUser}@{$deployHost}:{$remoteFile} " . dirname($localFile) . '/';
        }

        $this->deployer->writeln("💡 This may take a while for large files...");

        // Run the download command
        $this->deployer->runLocally($cmd);

        // Verify download
        $downloadTime = round(microtime(true) - $startTime, 2);
        $this->verifyDownload($localFile, $remoteSizeBytes, $downloadTime);
    }

    protected function verifyDownload(string $localFile, int $remoteSizeBytes, float $downloadTime): void
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
