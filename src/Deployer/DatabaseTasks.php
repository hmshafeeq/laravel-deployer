<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Services\DatabaseConfigExtractor;
use Shaf\LaravelDeployer\ValueObjects\DatabaseConfig;
use Shaf\LaravelDeployer\ValueObjects\BackupInfo;

class DatabaseTasks
{
    protected Deployer $deployer;
    protected DatabaseConfigExtractor $configExtractor;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
        $this->configExtractor = new DatabaseConfigExtractor($deployer);
    }

    /**
     * Get database configuration using the extractor service
     */
    protected function getDatabaseConfig(): DatabaseConfig
    {
        return $this->configExtractor->extract($this->deployer->getCurrentPath());
    }

    public function backup(): void
    {
        $this->deployer->task('database:backup', function ($deployer) {
            $deployPath = $deployer->getDeployPath();
            $backupPath = config('laravel-deployer.backup.path', 'shared/backups');
            $timestamp = date('Y-m-d_H-i-s');
            $backupFile = "{$deployPath}/{$backupPath}/db_backup_{$timestamp}.sql.gz";

            $deployer->writeln("run mkdir -p {$deployPath}/{$backupPath}");
            $deployer->run("mkdir -p {$deployPath}/{$backupPath}");

            $config = $this->getDatabaseConfig();
            $configFile = $config->createConfigFile();

            try {
                $this->performBackup($backupFile, $config, $configFile);
                $this->verifyBackup($backupFile);
                $this->cleanupOldBackups($deployPath, $backupPath);
            } catch (\Exception $e) {
                $this->handleBackupFailure($backupFile, $e);
            } finally {
                $this->cleanupConfigFile($configFile);
            }
        });
    }

    protected function performBackup(string $backupFile, DatabaseConfig $config, string $configFile): void
    {
        $this->deployer->writeln("💾 Starting database backup...");
        $this->deployer->writeln("📊 Database: {$config->database}");
        $this->deployer->writeln("🏠 Host: {$config->host}");
        $this->deployer->writeln("");
        $this->deployer->writeln("⏳ This may take a while for large databases...");

        $timeout = config('laravel-deployer.backup.timeout', 1800);
        $compressionLevel = config('laravel-deployer.backup.compression_level', 8);

        $dumpCommand = "timeout {$timeout} mysqldump --defaults-file={$configFile} --single-transaction --routines --triggers {$config->database} 2>&1";
        $compressCommand = "gzip -{$compressionLevel} > {$backupFile}";

        $this->deployer->writeln("run {$dumpCommand} | {$compressCommand}; echo \$?");
        $result = $this->deployer->run("{$dumpCommand} | {$compressCommand}; echo \$?");
        $exitCode = (int) trim($result);

        if ($exitCode !== 0) {
            throw new \RuntimeException("mysqldump failed with exit code: {$exitCode}");
        }
    }

    protected function verifyBackup(string $backupFile): void
    {
        $this->deployer->writeln("run test -f {$backupFile} && echo 'OK' || echo 'FAIL'");
        $fileExists = trim($this->deployer->run("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
        if (!empty($fileExists)) {
            $this->deployer->writeln($fileExists);
        }

        if ($fileExists !== 'OK') {
            throw new \RuntimeException("Backup file was not created: {$backupFile}");
        }

        $this->deployer->writeln("run stat -c%s {$backupFile} 2>/dev/null || stat -f%z {$backupFile} 2>/dev/null || echo 0");
        $fileSize = (int) trim($this->deployer->run("stat -c%s {$backupFile} 2>/dev/null || stat -f%z {$backupFile} 2>/dev/null || echo 0"));
        $this->deployer->writeln($fileSize);

        if ($fileSize < 100) {
            throw new \RuntimeException("Backup file is too small ({$fileSize} bytes), backup likely failed");
        }

        $this->deployer->writeln("");
        $this->deployer->writeln("✅ Database backup completed successfully!");

        $this->deployer->writeln("run ls -lh {$backupFile} | awk '{print \$5}'");
        $fileSizeHuman = trim($this->deployer->run("ls -lh {$backupFile} | awk '{print \$5}'"));
        $this->deployer->writeln($fileSizeHuman);

        $this->deployer->writeln("📁 Location: {$backupFile}");
        $this->deployer->writeln("📊 Size: {$fileSizeHuman}");
    }

    protected function cleanupOldBackups(string $deployPath, string $backupPath): void
    {
        $keepBackups = config('laravel-deployer.backup.keep', 3);

        $this->deployer->writeln("");
        $this->deployer->writeln("🧹 Cleaning up old backups (keeping {$keepBackups} most recent)...");

        $this->deployer->writeln("run cd {$deployPath}/{$backupPath} && ls -t db_backup_*.sql.gz | tail -n +".($keepBackups + 1)." | xargs -r rm -f");
        $this->deployer->run("cd {$deployPath}/{$backupPath} && ls -t db_backup_*.sql.gz | tail -n +".($keepBackups + 1)." | xargs -r rm -f");

        $this->deployer->writeln("run cd {$deployPath}/{$backupPath} && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l");
        $backupCount = (int) trim($this->deployer->run("cd {$deployPath}/{$backupPath} && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l"));
        $this->deployer->writeln($backupCount);

        $this->deployer->writeln("✅ Total backups on server: {$backupCount}");
    }

    protected function handleBackupFailure(string $backupFile, \Exception $e): void
    {
        $this->deployer->writeln("");
        $this->deployer->writeln("❌ Backup failed: " . $e->getMessage(), 'error');

        $fileExists = trim($this->deployer->run("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
        if ($fileExists === 'OK') {
            $this->deployer->run("rm -f {$backupFile}");
            $this->deployer->writeln("🧹 Cleaned up failed backup file");
        }

        throw $e;
    }

    protected function cleanupConfigFile(string $configFile): void
    {
        $this->deployer->writeln("run rm -f {$configFile} 2>/dev/null || true");
        $this->deployer->run("rm -f {$configFile} 2>/dev/null || true");
    }

    public function selectBackup(?string $selection = null): BackupInfo
    {
        $deployPath = $this->deployer->getDeployPath();
        $backupPath = config('laravel-deployer.backup.path', 'shared/backups');

        $backupList = $this->deployer->run("ls -lt {$deployPath}/{$backupPath}/db_backup_*.sql.gz 2>/dev/null || echo \"\"");
        if (empty($backupList)) {
            throw new \RuntimeException('No database backups found on server');
        }

        $backups = $this->deployer->run("ls -lht {$deployPath}/{$backupPath}/db_backup_*.sql.gz | head -10");
        $lines = array_filter(array_map('trim', explode("\n", trim($backups))));

        $choiceIndex = $this->determineBackupChoice($selection, $lines);

        if ($choiceIndex < 0 || $choiceIndex >= count($lines)) {
            throw new \RuntimeException('Invalid backup selection');
        }

        $parts = preg_split('/\s+/', $lines[$choiceIndex]);

        return new BackupInfo(
            path: $parts[8],
            name: basename($parts[8]),
            size: $parts[4]
        );
    }

    protected function determineBackupChoice(?string $selection, array $lines): int
    {
        if ($selection !== null) {
            if (strtolower($selection) === 'latest') {
                $choiceIndex = 0;
                if (isset($lines[0])) {
                    $parts = preg_split('/\s+/', $lines[0]);
                    $filename = basename($parts[8]);
                    $this->deployer->writeln("📋 Using latest backup: {$filename}");
                }
                return 0;
            } elseif (is_numeric($selection)) {
                $choiceIndex = (int) $selection - 1;
                $this->deployer->writeln("📋 Using backup #{$selection}: " . basename($lines[$choiceIndex]));
                return $choiceIndex;
            } else {
                throw new \RuntimeException("Invalid backup selection: {$selection}");
            }
        }

        // Interactive selection - default to latest
        $this->deployer->writeln("📋 Available database backups:");
        $this->deployer->writeln("");
        foreach ($lines as $index => $line) {
            $parts = preg_split('/\s+/', $line);
            $size = $parts[4];
            $filename = basename($parts[8]);
            $this->deployer->writeln("   " . ($index + 1) . ". {$filename} ({$size})");
        }
        $this->deployer->writeln("");
        $this->deployer->writeln("Using latest backup (automatic selection)");

        return 0;
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
            $remoteInfo = $this->getRemoteFileInfo($backup->path);

            $backupDir = './.deploy/downloads/backups';
            $deployer->runLocally("mkdir -p {$backupDir}");

            $deployer->writeln("📥 Downloading {$backup->name} ({$remoteInfo['human']})...");

            try {
                $this->downloadWithProgress($backup->path, "{$backupDir}/{$backup->name}", $remoteInfo['bytes'], $downloadMethod);
                $deployer->writeln("");
                $deployer->writeln("💡 To restore locally: php artisan database:restore " . $backup->name);
            } catch (\Exception $e) {
                $this->handleDownloadFailure("{$backupDir}/{$backup->name}", $remoteInfo['bytes'], $e);
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
            $method = '1';
            $this->deployer->writeln("Using rsync (default)");
        } else {
            $methodName = (strtolower($method) === 'scp' || $method === '2') ? 'SCP' : 'rsync';
            $this->deployer->writeln("⚡ Using {$methodName} download method");
        }

        $method = (strtolower($method) === 'scp' || $method === '2') ? '2' : '1';
        $startTime = microtime(true);

        if ($method === '2') {
            $this->deployer->writeln("🚀 Using SCP for maximum speed...");
            $cmd = "scp -o Compression=no -o TCPKeepAlive=yes -o ServerAliveInterval=60 {$deployUser}@{$deployHost}:{$remoteFile} " . dirname($localFile) . '/';
        } else {
            $this->deployer->writeln("⚡ Using optimized rsync (no compression, no bandwidth limit)...");
            $cmd = "rsync -av --partial --inplace {$deployUser}@{$deployHost}:{$remoteFile} " . dirname($localFile) . '/';
        }

        $this->deployer->writeln("💡 This may take a while for large files...");
        $this->deployer->runLocally($cmd);

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

    protected function handleDownloadFailure(string $localFile, int $remoteSizeBytes, \Exception $e): void
    {
        if (file_exists($localFile)) {
            $localSize = filesize($localFile);
            if ($localSize < ($remoteSizeBytes * 0.95)) {
                unlink($localFile);
                $this->deployer->writeln("🧹 Cleaned up partial download");
            } else {
                $this->deployer->writeln("📁 File appears complete, keeping download");
            }
        }
        throw new \RuntimeException('Download failed: ' . $e->getMessage());
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
        $localSizeHuman = $this->deployer->runLocally("ls -lh '{$localFile}' | awk '{print \$5}'");

        $remotePath = rtrim($remotePath, '/') . '/';
        $targetPath = $remotePath . $backupName;

        $this->deployer->writeln("📤 Uploading {$backupName} ({$localSizeHuman}) to {$targetServer}...");
        $this->deployer->writeln("");

        $this->uploadWithProgress($localFile, $targetServer, $sshKey, $remotePath, $localSize);

        $this->deployer->writeln("");
        $this->deployer->writeln("📁 Remote location: {$targetServer}:{$targetPath}");
    }

    protected function uploadWithProgress(string $localFile, string $targetServer, string $sshKey, string $remotePath, int $localSizeBytes): void
    {
        $startTime = microtime(true);
        $backupName = basename($localFile);
        $totalMB = round($localSizeBytes / 1024 / 1024, 1);
        $targetPath = $remotePath . $backupName;

        $this->deployer->writeln("🚀 Starting upload with SCP...");
        $this->deployer->writeln("📦 File: {$backupName} ({$totalMB} MB)");
        $this->deployer->writeln("");
        $this->deployer->writeln("💡 This may take a while for large files...");
        $this->deployer->writeln("");

        $scpCmd = sprintf(
            'scp -o Compression=no -o TCPKeepAlive=yes -o ServerAliveInterval=60 -o ServerAliveCountMax=240 -i %s %s %s',
            escapeshellarg($sshKey),
            escapeshellarg($localFile),
            escapeshellarg($targetServer . ':' . $remotePath)
        );

        $this->deployer->runLocally($scpCmd);

        // Verify upload
        $finalRemoteSize = (int) trim($this->deployer->runLocally(sprintf(
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

        $this->deployer->writeln("");
        $this->deployer->writeln("✅ Database backup uploaded successfully!");
        $this->deployer->writeln("⏱️  Total time: {$uploadTime}s");
        $this->deployer->writeln("🚀 Average speed: {$speedMBps} MB/s");
    }
}
