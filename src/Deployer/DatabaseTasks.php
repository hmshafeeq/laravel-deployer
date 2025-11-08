<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Actions\Database\BackupDatabaseAction;
use Shaf\LaravelDeployer\Actions\Database\DownloadDatabaseBackupAction;
use Shaf\LaravelDeployer\Actions\Database\SelectDatabaseBackupAction;
use Shaf\LaravelDeployer\ValueObjects\BackupInfo;

class DatabaseTasks
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
    }

    /**
     * Backup the database
     */
    public function backup(): void
    {
        $this->deployer->task('database:backup', function () {
            $backupFile = BackupDatabaseAction::run($this->deployer);
            $this->deployer->writeln("✅ Backup completed: {$backupFile}");
        });
    }

    /**
     * Download a database backup from the server
     */
    public function download(?string $backupSelection = null, ?string $downloadMethod = null): void
    {
        $this->deployer->task('database:download', function () use ($backupSelection, $downloadMethod) {
            $localFile = DownloadDatabaseBackupAction::run(
                $this->deployer,
                null,
                $backupSelection,
                $downloadMethod
            );

            $this->deployer->writeln("");
            $this->deployer->writeln("✅ Download completed!");
        });
    }

    /**
     * Upload a database backup to a server
     */
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

    /**
     * Select a backup from available backups
     */
    public function selectBackup(?string $selection = null): BackupInfo
    {
        return SelectDatabaseBackupAction::run($this->deployer, null, $selection);
    }

    /**
     * Get remote file information
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
     * Upload file with progress tracking
     */
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
