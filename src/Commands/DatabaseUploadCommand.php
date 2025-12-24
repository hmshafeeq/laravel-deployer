<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Number;

class DatabaseUploadCommand extends Command
{
    protected $signature = 'database:upload
                            {backup? : The backup file to upload (optional - will prompt if not provided)}
                            {--target= : Remote server target (e.g., user@host or IP)}
                            {--key= : SSH key path for authentication}
                            {--path= : Remote destination path (default: /home/ubuntu/)}
                            {--list : List available backups without uploading}
                            {--latest : Upload the latest backup without prompting}';

    protected $description = 'Upload database backup to remote server using rsync';

    protected array $backups = [];

    public function handle(): int
    {
        $this->info('📤 Database Upload');
        $this->line('');

        // Check if backups directory exists
        $backupsDir = base_path('.deploy/downloads/backups');
        if (! File::exists($backupsDir)) {
            $this->error('❌ No backups directory found.');
            $this->info('💡 Run \'php artisan database:download\' first to download backups.');

            return self::FAILURE;
        }

        // Get available backups
        $this->backups = $this->getAvailableBackups($backupsDir);

        if (empty($this->backups)) {
            $this->error('❌ No database backups found in .deploy/downloads/backups/ directory.');
            $this->info('💡 Run \'php artisan database:download\' to download backups from server.');

            return self::FAILURE;
        }

        // Handle --list option
        if ($this->option('list')) {
            $this->displayBackups();

            return self::SUCCESS;
        }

        // Get selected backup
        $selectedBackup = $this->getSelectedBackup();
        if (! $selectedBackup) {
            $this->info('ℹ️  Upload cancelled.');

            return self::SUCCESS;
        }

        // Get upload configuration
        $uploadConfig = $this->getUploadConfig();
        if (! $uploadConfig) {
            return self::FAILURE;
        }

        // Confirm upload
        if (! $this->confirmUpload($selectedBackup, $uploadConfig)) {
            $this->info('ℹ️  Upload cancelled.');

            return self::SUCCESS;
        }

        // Perform upload
        if (! $this->uploadBackup($selectedBackup, $uploadConfig)) {
            return self::FAILURE;
        }

        $this->line('');
        $this->info('✅ Database backup uploaded successfully!');
        $this->line('');
        $this->info('💡 To restore on the remote server:');
        $this->line('   1. SSH into the server:');
        $this->line("      ssh {$uploadConfig['target']}");
        $this->line('');
        $this->line('   2. Restore the database:');
        $backupName = basename($selectedBackup);
        $remotePath = rtrim($uploadConfig['path'], '/').'/'.$backupName;
        $this->line("      gunzip < {$remotePath} | mysql -u username -p database_name");

        return self::SUCCESS;
    }

    protected function getAvailableBackups(string $backupsDir): array
    {
        $files = File::glob($backupsDir.'/db_backup_*.sql.gz');

        // Sort by modification time (newest first)
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $files;
    }

    protected function displayBackups(): void
    {
        $this->info('📋 Available database backups:');
        $this->line('');

        foreach ($this->backups as $index => $backup) {
            $name = basename($backup);
            $size = Number::fileSize(filesize($backup));
            $date = date('Y-m-d H:i:s', filemtime($backup));

            $this->line(sprintf('   %d. %s (%s) - %s', $index + 1, $name, $size, $date));
        }

        $this->line('');
    }

    protected function getSelectedBackup(): ?string
    {
        // Handle --latest option
        if ($this->option('latest')) {
            return $this->backups[0];
        }

        // Handle backup argument
        $backupArg = $this->argument('backup');
        if ($backupArg) {
            // If it's a number, treat as index
            if (is_numeric($backupArg)) {
                $index = (int) $backupArg - 1;
                if (isset($this->backups[$index])) {
                    return $this->backups[$index];
                }
                $this->error("❌ Invalid backup selection: {$backupArg}");

                return null;
            }

            // Treat as filename
            $backupPath = base_path('.deploy/downloads/backups/'.$backupArg);
            if (File::exists($backupPath)) {
                return $backupPath;
            }

            $this->error("❌ Backup file not found: {$backupArg}");

            return null;
        }

        // Interactive selection
        $this->displayBackups();

        $choice = $this->ask(
            'Enter backup number to upload (1-'.count($this->backups).') or press Enter for latest',
            '1'
        );

        if (! is_numeric($choice) || $choice < 1 || $choice > count($this->backups)) {
            $this->error("❌ Invalid backup selection: {$choice}");

            return null;
        }

        return $this->backups[$choice - 1];
    }

    protected function getUploadConfig(): ?array
    {
        $config = [
            'target' => $this->option('target'),
            'key' => $this->option('key'),
            'path' => $this->option('path') ?: '/home/ubuntu/',
        ];

        // Prompt for target if not provided
        if (! $config['target']) {
            $config['target'] = $this->ask('Enter remote server target (user@host or IP)');
        }

        if (! $config['target']) {
            $this->error('❌ Remote server target is required.');

            return null;
        }

        // Prompt for SSH key if not provided
        if (! $config['key']) {
            $defaultKey = $_SERVER['HOME'].'/.ssh/id_rsa';
            $config['key'] = $this->ask('Enter SSH key path', $defaultKey);
        }

        if (! $config['key']) {
            $this->error('❌ SSH key path is required.');

            return null;
        }

        // Validate SSH key exists
        if (! File::exists($config['key'])) {
            $this->error("❌ SSH key not found: {$config['key']}");

            return null;
        }

        // Ensure path ends with /
        $config['path'] = rtrim($config['path'], '/').'/';

        return $config;
    }

    protected function confirmUpload(string $selectedBackup, array $uploadConfig): bool
    {
        $backupName = basename($selectedBackup);
        $backupSize = Number::fileSize(filesize($selectedBackup));

        $this->info("📋 Selected backup: {$backupName} ({$backupSize})");
        $this->line('');
        $this->info('🌐 Upload configuration:');
        $this->line("   Target: {$uploadConfig['target']}");
        $this->line("   SSH Key: {$uploadConfig['key']}");
        $this->line("   Remote Path: {$uploadConfig['path']}");
        $this->line('');

        return $this->confirm('Upload this backup to the remote server?', true);
    }

    protected function uploadBackup(string $selectedBackup, array $uploadConfig): bool
    {
        $backupName = basename($selectedBackup);
        $backupSizeMb = round(filesize($selectedBackup) / 1024 / 1024, 2);
        $remotePath = $uploadConfig['path'].$backupName;

        $this->info('🚀 Starting upload...');
        $this->info("📊 Backup size: {$backupSizeMb}MB");
        $this->line('');

        // Build SCP command with progress and keepalive options
        $scpCommand = sprintf(
            'scp -o ServerAliveInterval=30 -o ServerAliveCountMax=10 -i %s %s %s:%s',
            escapeshellarg($uploadConfig['key']),
            escapeshellarg($selectedBackup),
            escapeshellarg($uploadConfig['target']),
            escapeshellarg($remotePath)
        );

        $this->info("📤 Uploading to {$uploadConfig['target']}:{$remotePath}");
        $startTime = microtime(true);

        $result = Process::timeout(3600)
            ->path(base_path())
            ->run($scpCommand, function ($type, $buffer) {
                echo $buffer;
            });

        if (! $result->successful()) {
            $this->line('');
            $this->error('❌ Upload failed');
            if ($result->errorOutput()) {
                $this->error($result->errorOutput());
            }

            return false;
        }

        $duration = round(microtime(true) - $startTime, 2);
        $speed = $duration > 0 ? round($backupSizeMb / $duration, 2) : 0;

        $this->line('');
        $this->info("✓ Upload completed in {$duration}s ({$speed}MB/s)");

        // Verify upload by checking remote file size
        $this->info('🔍 Verifying upload...');

        $sshCommand = sprintf(
            'ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=10 -i %s %s "stat -c %%s %s 2>/dev/null || stat -f %%z %s 2>/dev/null"',
            escapeshellarg($uploadConfig['key']),
            escapeshellarg($uploadConfig['target']),
            escapeshellarg($remotePath),
            escapeshellarg($remotePath)
        );

        $result = Process::timeout(30)
            ->path(base_path())
            ->run($sshCommand);

        if ($result->successful()) {
            $remoteSize = (int) trim($result->output());
            $localSize = filesize($selectedBackup);

            if ($remoteSize === $localSize) {
                $this->info('✓ File size verified: '.number_format($localSize).' bytes');
            } else {
                $this->warn("⚠ File size mismatch - Local: {$localSize}, Remote: {$remoteSize}");
            }
        } else {
            $this->warn('⚠ Could not verify remote file size');
        }

        return true;
    }
}
