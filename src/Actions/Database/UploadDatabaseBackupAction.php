<?php

namespace Shaf\LaravelDeployer\Actions\Database;

class UploadDatabaseBackupAction extends AbstractDatabaseAction
{
    protected string $localFile;
    protected string $targetServer;
    protected string $sshKey;
    protected string $remotePath;

    public function __construct(
        \Shaf\LaravelDeployer\Deployer $deployer,
        string $localFile,
        string $targetServer,
        string $sshKey,
        string $remotePath = '/home/ubuntu/'
    ) {
        parent::__construct($deployer);
        $this->localFile = $localFile;
        $this->targetServer = $targetServer;
        $this->sshKey = $sshKey;
        $this->remotePath = $remotePath;
    }

    public function execute(): void
    {
        // Verify local backup file exists
        if (!file_exists($this->localFile)) {
            throw new \RuntimeException("Backup file not found: {$this->localFile}");
        }

        // Verify SSH key exists
        if (!file_exists($this->sshKey)) {
            throw new \RuntimeException("SSH key not found: {$this->sshKey}");
        }

        $backupName = basename($this->localFile);
        $localSize = filesize($this->localFile);
        $localSizeHuman = $this->deployer->runLocally("ls -lh '{$this->localFile}' | awk '{print \$5}'");

        // Ensure remote path ends with /
        $remotePath = rtrim($this->remotePath, '/') . '/';
        $targetPath = $remotePath . $backupName;

        $this->deployer->writeln("📤 Uploading {$backupName} ({$localSizeHuman}) to {$this->targetServer}...");
        $this->deployer->writeln("");

        $this->uploadWithProgress($remotePath, $localSize);

        $this->deployer->writeln("");
        $this->deployer->writeln("📁 Remote location: {$this->targetServer}:{$targetPath}");
    }

    protected function uploadWithProgress(string $remotePath, int $localSizeBytes): void
    {
        $startTime = microtime(true);

        $backupName = basename($this->localFile);
        $totalMB = round($localSizeBytes / 1024 / 1024, 1);
        $targetPath = $remotePath . $backupName;

        $this->deployer->writeln("🚀 Starting upload with SCP...");
        $this->deployer->writeln("📦 File: {$backupName} ({$totalMB} MB)");
        $this->deployer->writeln("");
        $this->deployer->writeln("💡 This may take a while for large files...");
        $this->deployer->writeln("");

        // Build SCP command with keepalive options
        $scpCmd = sprintf(
            'scp -o Compression=no -o TCPKeepAlive=yes -o ServerAliveInterval=60 -o ServerAliveCountMax=240 -i %s %s %s',
            escapeshellarg($this->sshKey),
            escapeshellarg($this->localFile),
            escapeshellarg($this->targetServer . ':' . $remotePath)
        );

        // Run SCP command
        $this->deployer->runLocally($scpCmd);

        // Verify upload by checking remote file size
        $finalRemoteSize = (int) trim($this->deployer->runLocally(sprintf(
            'ssh -i %s %s "stat -c%%s %s 2>/dev/null || stat -f%%z %s 2>/dev/null || echo 0"',
            escapeshellarg($this->sshKey),
            escapeshellarg($this->targetServer),
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

    public function getName(): string
    {
        return 'database:upload';
    }
}
