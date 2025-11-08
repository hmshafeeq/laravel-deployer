<?php

namespace Shaf\LaravelDeployer\Actions\Database;

use Shaf\LaravelDeployer\Support\Abstract\DatabaseAction;
use Shaf\LaravelDeployer\ValueObjects\BackupInfo;

class DownloadDatabaseBackupAction extends DatabaseAction
{
    public function execute(?string $backupSelection = null, ?string $downloadMethod = null): string
    {
        $backup = SelectDatabaseBackupAction::run($this->deployer, null, $backupSelection);
        $remoteInfo = $this->getRemoteFileInfo($backup->path);

        $localFile = $this->prepareLocalFile($backup->name);

        $this->writeln("📥 Downloading {$backup->name} ({$remoteInfo['human']})...");

        try {
            $this->downloadWithProgress($backup->path, $localFile, $remoteInfo['bytes'], $downloadMethod);
            $this->writeln("");
            $this->writeln("💡 To restore locally: php artisan database:restore {$backup->name}");

            return $localFile;
        } catch (\Exception $e) {
            $this->handleDownloadFailure($localFile, $remoteInfo['bytes'], $e);
            throw $e;
        }
    }

    protected function getRemoteFileInfo(string $filePath): array
    {
        $sizeBytes = (int) trim($this->run("stat -c%s {$filePath}"));
        $sizeHuman = trim($this->run("ls -lh {$filePath} | awk '{print \$5}'"));

        $fileCheck = trim($this->run("test -r {$filePath} && echo 'OK' || echo 'FAIL'"));
        if ($fileCheck !== 'OK') {
            throw new \RuntimeException("Cannot access backup file on remote server: {$filePath}");
        }

        return ['bytes' => $sizeBytes, 'human' => $sizeHuman];
    }

    protected function prepareLocalFile(string $backupName): string
    {
        $backupDir = './.deploy/downloads/backups';
        $this->deployer->runLocally("mkdir -p {$backupDir}");

        return "{$backupDir}/{$backupName}";
    }

    protected function downloadWithProgress(string $remoteFile, string $localFile, int $remoteSizeBytes, ?string $method = null): void
    {
        $deployUser = $this->deployer->get('remote_user');
        $deployHost = $this->deployer->get('hostname');

        $method = $this->determineDownloadMethod($method);
        $startTime = microtime(true);

        $cmd = $this->buildDownloadCommand($method, $deployUser, $deployHost, $remoteFile, $localFile);

        $this->writeln("💡 This may take a while for large files...");
        $this->deployer->runLocally($cmd);

        $downloadTime = round(microtime(true) - $startTime, 2);
        $this->verifyDownload($localFile, $remoteSizeBytes, $downloadTime);
    }

    protected function determineDownloadMethod(?string $method): string
    {
        if ($method === null) {
            $this->writeln("💡 Speed optimization tips:");
            $this->writeln("   • Option 1 (rsync): Best for reliability, resume capability");
            $this->writeln("   • Option 2 (scp): Often faster for large files, no resume");
            $this->writeln("");
            $method = '1';
            $this->writeln("Using rsync (default)");
        } else {
            $methodName = (strtolower($method) === 'scp' || $method === '2') ? 'SCP' : 'rsync';
            $this->writeln("⚡ Using {$methodName} download method");
        }

        return (strtolower($method) === 'scp' || $method === '2') ? '2' : '1';
    }

    protected function buildDownloadCommand(string $method, string $user, string $host, string $remoteFile, string $localFile): string
    {
        if ($method === '2') {
            $this->writeln("🚀 Using SCP for maximum speed...");
            return "scp -o Compression=no -o TCPKeepAlive=yes -o ServerAliveInterval=60 {$user}@{$host}:{$remoteFile} " . dirname($localFile) . '/';
        } else {
            $this->writeln("⚡ Using optimized rsync (no compression, no bandwidth limit)...");
            return "rsync -av --partial --inplace {$user}@{$host}:{$remoteFile} " . dirname($localFile) . '/';
        }
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

        $this->writeln("");
        $this->writeln("✅ Database backup downloaded successfully!");
        $this->writeln("📁 Location: {$localFile}");
        $this->writeln("📊 Size: {$localSizeHuman}");
        $this->writeln("⏱️  Time: {$downloadTime}s");
        $this->writeln("🚀 Speed: {$speedMBps} MB/s");
    }

    protected function handleDownloadFailure(string $localFile, int $remoteSizeBytes, \Exception $e): void
    {
        if (file_exists($localFile)) {
            $localSize = filesize($localFile);
            if ($localSize < ($remoteSizeBytes * 0.95)) {
                unlink($localFile);
                $this->writeln("🧹 Cleaned up partial download");
            } else {
                $this->writeln("📁 File appears complete, keeping download");
            }
        }
    }
}
