<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;
use Symfony\Component\Process\Process;

class DatabaseTasks
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config
    ) {
    }

    protected function run(string $command): string
    {
        return $this->executor->execute($command);
    }

    protected function runLocally(string $command): string
    {
        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(900);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Command failed: {$command}\n{$process->getErrorOutput()}");
        }

        return trim($process->getOutput());
    }

    protected function getDeployPath(): string
    {
        return $this->config->deployPath;
    }

    protected function getCurrentPath(): string
    {
        return $this->config->deployPath . '/current';
    }

    protected function task(string $name, callable $callback): void
    {
        $this->output->info("task {$name}");
        $callback($this);
    }

    protected function getDatabaseConfigWithFile(): array
    {
        $currentPath = $this->getCurrentPath();

        $this->output->info("🔍 Getting database configuration...");

        $this->output->command("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.default');\"");
        $connection = trim($this->run("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.default');\""));
        $this->output->commandOutput($connection);

        $this->output->command("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.host');\"");
        $host = trim($this->run("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.host');\""));
        $this->output->commandOutput($host);

        $this->output->command("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.database');\"");
        $database = trim($this->run("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.database');\""));
        $this->output->commandOutput($database);

        $this->output->command("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.username');\"");
        $username = trim($this->run("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.username');\""));
        $this->output->commandOutput($username);

        $this->output->command("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.password');\"");
        $password = trim($this->run("cd {$currentPath} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.password');\""));
        $this->output->commandOutput($password);

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
        $this->output->command("echo '[client]' > {$configFile}");
        $this->run("echo '[client]' > {$configFile}");

        $this->output->command("echo 'host={$host}' >> {$configFile}");
        $this->run("echo 'host={$host}' >> {$configFile}");

        $this->output->command("echo 'user={$username}' >> {$configFile}");
        $this->run("echo 'user={$username}' >> {$configFile}");

        $this->output->command("echo 'password={$password}' >> {$configFile}");
        $this->run("echo 'password={$password}' >> {$configFile}");

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
        $this->task('database:backup', function () {
            $timestamp = date('Y-m-d_H-i-s');
            $deployPath = $this->getDeployPath();
            $backupFile = "{$deployPath}/shared/backups/db_backup_{$timestamp}.sql.gz";

            $this->output->command("mkdir -p {$deployPath}/shared/backups");
            $this->run("mkdir -p {$deployPath}/shared/backups");

            $dbConfig = $this->getDatabaseConfigWithFile();

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
                $result = $this->run("{$dumpCommand} | {$compressCommand}; echo \$?");
                $exitCode = (int) trim($result);

                if ($exitCode !== 0) {
                    throw new \RuntimeException("mysqldump failed with exit code: {$exitCode}");
                }

                // Verify backup file was created and has content
                $this->output->command("test -f {$backupFile} && echo 'OK' || echo 'FAIL'");
                $fileExists = trim($this->run("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
                if (!empty($fileExists)) {
                    $this->output->commandOutput($fileExists);
                }

                if ($fileExists !== 'OK') {
                    throw new \RuntimeException("Backup file was not created: {$backupFile}");
                }

                $this->output->command("stat -c%s {$backupFile} 2>/dev/null || stat -f%z {$backupFile} 2>/dev/null || echo 0");
                $fileSize = (int) trim($this->run("stat -c%s {$backupFile} 2>/dev/null || stat -f%z {$backupFile} 2>/dev/null || echo 0"));
                $this->output->commandOutput((string) $fileSize);

                if ($fileSize < 100) {
                    throw new \RuntimeException("Backup file is too small ({$fileSize} bytes), backup likely failed");
                }

                $this->output->newLine();
                $this->output->success("Database backup completed successfully!");

                $this->output->command("ls -lh {$backupFile} | awk '{print \$5}'");
                $fileSizeHuman = trim($this->run("ls -lh {$backupFile} | awk '{print \$5}'"));
                $this->output->commandOutput($fileSizeHuman);

                $this->output->info("📁 Location: {$backupFile}");
                $this->output->info("📊 Size: {$fileSizeHuman}");

                // Clean up old backups (keep only 3 most recent)
                $this->output->newLine();
                $this->output->info("🧹 Cleaning up old backups (keeping 3 most recent)...");

                $this->output->command("cd {$deployPath}/shared/backups && ls -t db_backup_*.sql.gz | tail -n +4 | xargs -r rm -f");
                $this->run("cd {$deployPath}/shared/backups && ls -t db_backup_*.sql.gz | tail -n +4 | xargs -r rm -f");

                $this->output->command("cd {$deployPath}/shared/backups && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l");
                $backupCount = (int) trim($this->run("cd {$deployPath}/shared/backups && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l"));
                $this->output->commandOutput((string) $backupCount);

                $this->output->success("Total backups on server: {$backupCount}");

            } catch (\Exception $e) {
                $this->output->newLine();
                $this->output->error("Backup failed: " . $e->getMessage());

                // Clean up failed backup file if it exists
                $fileExists = trim($this->run("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
                if ($fileExists === 'OK') {
                    $this->run("rm -f {$backupFile}");
                    $this->output->info("🧹 Cleaned up failed backup file");
                }

                throw $e;
            } finally {
                // Always clean up config file
                $this->output->command("rm -f {$dbConfig['config_file']} 2>/dev/null || true");
                $this->run("rm -f {$dbConfig['config_file']} 2>/dev/null || true");
            }
        });
    }

    public function selectBackup(?string $selection = null): array
    {
        $deployPath = $this->getDeployPath();

        $backupList = $this->run("ls -lt {$deployPath}/shared/backups/db_backup_*.sql.gz 2>/dev/null || echo \"\"");
        if (empty($backupList)) {
            throw new \RuntimeException('No database backups found on server');
        }

        $backups = $this->run("ls -lht {$deployPath}/shared/backups/db_backup_*.sql.gz | head -10");
        $lines = array_filter(array_map('trim', explode("\n", trim($backups))));

        // If selection argument provided, don't show interactive list
        if ($selection !== null) {
            if (strtolower($selection) === 'latest') {
                $choiceIndex = 0;
                if (isset($lines[0])) {
                    $parts = preg_split('/\s+/', $lines[0]);
                    $filename = basename($parts[8]);
                    $this->output->info("📋 Using latest backup: {$filename}");
                }
            } elseif (is_numeric($selection)) {
                $choiceIndex = (int) $selection - 1;
                $this->output->info("📋 Using backup #{$selection}: " . basename($lines[$choiceIndex]));
            } else {
                throw new \RuntimeException("Invalid backup selection: {$selection}");
            }
        } else {
            // Interactive selection
            $this->output->info("📋 Available database backups:");
            $this->output->newLine();
            foreach ($lines as $index => $line) {
                $parts = preg_split('/\s+/', $line);
                $size = $parts[4];
                $filename = basename($parts[8]);
                $this->output->info("   " . ($index + 1) . ". {$filename} ({$size})");
            }
            $this->output->newLine();

            // For automated selection, default to latest
            $choiceIndex = 0;
            $this->output->info("Using latest backup (automatic selection)");
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
        $sizeBytes = (int) trim($this->run("stat -c%s {$filePath}"));
        $sizeHuman = trim($this->run("ls -lh {$filePath} | awk '{print \$5}'"));

        $fileCheck = trim($this->run("test -r {$filePath} && echo 'OK' || echo 'FAIL'"));
        if ($fileCheck !== 'OK') {
            throw new \RuntimeException("Cannot access backup file on remote server: {$filePath}");
        }

        return ['bytes' => $sizeBytes, 'human' => $sizeHuman];
    }

    public function download(?string $backupSelection = null, ?string $downloadMethod = null): void
    {
        $this->task('database:download', function () use ($backupSelection, $downloadMethod) {
            $backup = $this->selectBackup($backupSelection);
            $remoteInfo = $this->getRemoteFileInfo($backup['path']);

            $backupDir = './.deploy/downloads/backups';
            $this->runLocally("mkdir -p {$backupDir}");

            $this->output->info("📥 Downloading {$backup['name']} ({$remoteInfo['human']})...");

            try {
                $this->downloadWithProgress($backup['path'], "{$backupDir}/{$backup['name']}", $remoteInfo['bytes'], $downloadMethod);
                $this->output->newLine();
                $this->output->info("💡 To restore locally: php artisan database:restore " . $backup['name']);
            } catch (\Exception $e) {
                $localFile = "{$backupDir}/{$backup['name']}";
                if (file_exists($localFile)) {
                    $localSize = filesize($localFile);
                    if ($localSize < ($remoteInfo['bytes'] * 0.95)) {
                        unlink($localFile);
                        $this->output->info("🧹 Cleaned up partial download");
                    } else {
                        $this->output->info("📁 File appears complete, keeping download");
                    }
                }
                throw new \RuntimeException('Download failed: ' . $e->getMessage());
            }
        });
    }

    protected function downloadWithProgress(string $remoteFile, string $localFile, int $remoteSizeBytes, ?string $method = null): void
    {
        $deployUser = $this->config->remoteUser;
        $deployHost = $this->config->hostname;

        if ($method === null) {
            $this->output->info("💡 Speed optimization tips:");
            $this->output->info("   • Option 1 (rsync): Best for reliability, resume capability");
            $this->output->info("   • Option 2 (scp): Often faster for large files, no resume");
            $this->output->newLine();
            $method = '1'; // Default to rsync
            $this->output->info("Using rsync (default)");
        } else {
            $methodName = (strtolower($method) === 'scp' || $method === '2') ? 'SCP' : 'rsync';
            $this->output->info("⚡ Using {$methodName} download method");
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
            $this->output->info("🚀 Using SCP for maximum speed...");
            $cmd = "scp -o Compression=no -o TCPKeepAlive=yes -o ServerAliveInterval=60 {$deployUser}@{$deployHost}:{$remoteFile} " . dirname($localFile) . '/';
        } else {
            $this->output->info("⚡ Using optimized rsync (no compression, no bandwidth limit)...");
            $cmd = "rsync -av --partial --inplace {$deployUser}@{$deployHost}:{$remoteFile} " . dirname($localFile) . '/';
        }

        $this->output->info("💡 This may take a while for large files...");

        // Run the download command
        $this->runLocally($cmd);

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

        $localSizeHuman = $this->runLocally("ls -lh '{$localFile}' | awk '{print \$5}'");
        $speedMBps = round(($localSize / 1024 / 1024) / $downloadTime, 2);

        $this->output->newLine();
        $this->output->success("Database backup downloaded successfully!");
        $this->output->info("📁 Location: {$localFile}");
        $this->output->info("📊 Size: {$localSizeHuman}");
        $this->output->info("⏱️  Time: {$downloadTime}s");
        $this->output->info("🚀 Speed: {$speedMBps} MB/s");
    }

    public function upload(string $localFile, string $targetServer, string $sshKey, string $remotePath = '/home/ubuntu/'): void
    {
        // Verify local backup file exists
        if (!file_exists($localFile)) {
            throw new \RuntimeException("Backup file not found: {$localFile}");
        }

        // Verify SSH key exists
        if (!file_exists($sshKey)) {
            throw new \RuntimeException("SSH key not found: {$sshKey}");
        }

        $backupName = basename($localFile);
        $localSize = filesize($localFile);
        $localSizeHuman = $this->runLocally("ls -lh '{$localFile}' | awk '{print \$5}'");

        // Ensure remote path ends with /
        $remotePath = rtrim($remotePath, '/') . '/';
        $targetPath = $remotePath . $backupName;

        $this->output->info("📤 Uploading {$backupName} ({$localSizeHuman}) to {$targetServer}...");
        $this->output->newLine();

        $this->uploadWithProgress($localFile, $targetServer, $sshKey, $remotePath, $localSize);

        $this->output->newLine();
        $this->output->info("📁 Remote location: {$targetServer}:{$targetPath}");
    }

    protected function uploadWithProgress(string $localFile, string $targetServer, string $sshKey, string $remotePath, int $localSizeBytes): void
    {
        $startTime = microtime(true);

        $backupName = basename($localFile);
        $totalMB = round($localSizeBytes / 1024 / 1024, 1);
        $targetPath = $remotePath . $backupName;

        $this->output->info("🚀 Starting upload with SCP...");
        $this->output->info("📦 File: {$backupName} ({$totalMB} MB)");
        $this->output->newLine();
        $this->output->info("💡 This may take a while for large files...");
        $this->output->newLine();

        // Build SCP command with keepalive options
        $scpCmd = sprintf(
            'scp -o Compression=no -o TCPKeepAlive=yes -o ServerAliveInterval=60 -o ServerAliveCountMax=240 -i %s %s %s',
            escapeshellarg($sshKey),
            escapeshellarg($localFile),
            escapeshellarg($targetServer . ':' . $remotePath)
        );

        // Run SCP command
        $this->runLocally($scpCmd);

        // Verify upload by checking remote file size
        $finalRemoteSize = (int) trim($this->runLocally(sprintf(
            'ssh -i %s %s "stat -c%%s %s 2>/dev/null || stat -f%%z %s 2>/dev/null || echo 0"',
            escapeshellarg($sshKey),
            escapeshellarg($targetServer),
            escapeshellarg($targetPath),
            escapeshellarg($targetPath)
        )));

        if ($finalRemoteSize !== $localSizeBytes) {
            throw new \RuntimeException("Upload failed: Size mismatch (local: {$localSizeBytes}, remote: {$finalRemoteSize})");
        }

        $uploadTime = round(microtime(true) - $startTime, 2);
        $speedMBps = round(($localSizeBytes / 1024 / 1024) / $uploadTime, 2);

        $this->output->newLine();
        $this->output->success("Database backup uploaded successfully!");
        $this->output->info("⏱️  Total time: {$uploadTime}s");
        $this->output->info("🚀 Average speed: {$speedMBps} MB/s");
    }
}
