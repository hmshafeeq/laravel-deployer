<?php

namespace Shaf\LaravelDeployer\Actions\Database;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;
use Symfony\Component\Process\Process;

class DownloadDatabaseAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config
    ) {
    }

    public function execute(?string $backupSelection = null, ?string $downloadMethod = null): void
    {
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
    }

    protected function selectBackup(?string $selection = null): array
    {
        $deployPath = $this->config->deployPath;

        $backupList = $this->executor->execute("ls -lt {$deployPath}/shared/backups/db_backup_*.sql.gz 2>/dev/null || echo \"\"");
        if (empty($backupList)) {
            throw new \RuntimeException('No database backups found on server');
        }

        $backups = $this->executor->execute("ls -lht {$deployPath}/shared/backups/db_backup_*.sql.gz | head -10");
        $lines = array_filter(array_map('trim', explode("\n", trim($backups))));

        $choiceIndex = 0;

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

    protected function getRemoteFileInfo(string $filePath): array
    {
        $sizeBytes = (int) trim($this->executor->execute("stat -c%s {$filePath}"));
        $sizeHuman = trim($this->executor->execute("ls -lh {$filePath} | awk '{print \$5}'"));

        $fileCheck = trim($this->executor->execute("test -r {$filePath} && echo 'OK' || echo 'FAIL'"));
        if ($fileCheck !== 'OK') {
            throw new \RuntimeException("Cannot access backup file on remote server: {$filePath}");
        }

        return ['bytes' => $sizeBytes, 'human' => $sizeHuman];
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
            $method = '1';
            $this->output->info("Using rsync (default)");
        } else {
            $methodName = (strtolower($method) === 'scp' || $method === '2') ? 'SCP' : 'rsync';
            $this->output->info("⚡ Using {$methodName} download method");
        }

        $method = (strtolower($method) === 'scp' || $method === '2') ? '2' : '1';

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
}
