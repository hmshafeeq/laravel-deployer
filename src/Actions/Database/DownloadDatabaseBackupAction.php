<?php

namespace Shaf\LaravelDeployer\Actions\Database;

class DownloadDatabaseBackupAction extends AbstractDatabaseAction
{
    protected ?string $backupSelection;
    protected ?string $downloadMethod;

    public function __construct(\Shaf\LaravelDeployer\Deployer $deployer, ?string $backupSelection = null, ?string $downloadMethod = null)
    {
        parent::__construct($deployer);
        $this->backupSelection = $backupSelection;
        $this->downloadMethod = $downloadMethod;
    }

    public function execute(): void
    {
        $backup = $this->databaseService->selectBackup($this->backupSelection);
        $remoteInfo = $this->databaseService->getRemoteFileInfo($backup['path']);

        $backupDir = './.deploy/downloads/backups';
        $this->deployer->runLocally("mkdir -p {$backupDir}");

        $this->deployer->writeln("📥 Downloading {$backup['name']} ({$remoteInfo['human']})...");

        try {
            $this->downloadWithProgress($backup['path'], "{$backupDir}/{$backup['name']}", $remoteInfo['bytes'], $this->downloadMethod);
            $this->deployer->writeln("");
            $this->deployer->writeln("💡 To restore locally: php artisan database:restore " . $backup['name']);
        } catch (\Exception $e) {
            $localFile = "{$backupDir}/{$backup['name']}";
            if (file_exists($localFile)) {
                $localSize = filesize($localFile);
                if ($localSize < ($remoteInfo['bytes'] * 0.95)) {
                    unlink($localFile);
                    $this->deployer->writeln("🧹 Cleaned up partial download");
                } else {
                    $this->deployer->writeln("📁 File appears complete, keeping download");
                }
            }
            throw new \RuntimeException('Download failed: ' . $e->getMessage());
        }
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
        $this->databaseService->verifyDownload($localFile, $remoteSizeBytes, $downloadTime);
    }

    public function getName(): string
    {
        return 'database:download';
    }
}
