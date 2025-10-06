<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DatabaseDownloadCommand extends Command
{
    protected $signature = 'database:download 
                            {server? : Server name (staging, production, etc.)}
                            {backup? : Backup selection (latest, 1-10, or filename)}
                            {method? : Download method (rsync or scp)}
                            {--select : Show available servers and select interactively}';

    protected $description = 'Download database backup from remote server (wrapper for deployer)';

    public function handle(): int
    {
        $this->info('📥 Database Download');
        $this->line('');

        $serverName = $this->getServerName();
        if (! $serverName) {
            return self::FAILURE;
        }

        $this->info("🌐 Target server: {$serverName}");
        $this->line('');

        // Ensure backups directory exists
        $backupsDir = base_path('backups');
        if (! File::exists($backupsDir)) {
            File::makeDirectory($backupsDir, 0755, true);
            $this->info("📁 Created backups directory: {$backupsDir}");
        }

        // Build deployer command with arguments
        $command = sprintf(
            'php vendor/bin/dep database:download %s',
            escapeshellarg($serverName)
        );

        // Add backup selection argument if provided
        $backupSelection = $this->argument('backup');
        if ($backupSelection) {
            $command .= ' '.escapeshellarg($backupSelection);
        }

        // Add method argument if provided
        $method = $this->argument('method');
        if ($method) {
            $command .= ' '.escapeshellarg($method);
        }

        $this->info('🚀 Running: '.$command);
        $this->line('');

        // Set environment variables for arguments if provided
        $env = [];
        if ($backupSelection) {
            $env['DEPLOYER_BACKUP_SELECTION'] = $backupSelection;
        }
        if ($method) {
            $env['DEPLOYER_DOWNLOAD_METHOD'] = $method;
        }

        // Run with real-time output streaming and environment variables
        $result = Process::timeout(3600)
            ->path(base_path())
            ->env($env)
            ->run($command, function ($type, $buffer) {
                echo $buffer;
            });

        if ($result->successful()) {
            $this->line('');
            $this->info('✅ Database download completed successfully!');
            $this->info('💡 To restore the backup:');
            $this->line('   php artisan database:restore');
            $this->line('');
            $this->info('📁 Downloaded backups are in: ./backups/');

            return self::SUCCESS;
        } else {
            $this->line('');
            $this->error('❌ Database download failed');

            return self::FAILURE;
        }
    }

    protected function getServerName(): ?string
    {
        if ($this->option('select')) {
            return $this->selectServerInteractively();
        }

        $serverName = $this->argument('server');
        if ($serverName) {
            if (! $this->validateServer($serverName)) {
                return null;
            }

            return $serverName;
        }

        // If no server provided, show available servers
        $servers = $this->getAvailableServers();
        if (empty($servers)) {
            return null;
        }

        if (count($servers) === 1) {
            return $servers[0];
        }

        return $this->selectServerInteractively();
    }

    protected function selectServerInteractively(): ?string
    {
        $servers = $this->getAvailableServers();
        if (empty($servers)) {
            return null;
        }

        $this->info('📋 Available servers:');
        foreach ($servers as $index => $server) {
            $this->line('   '.($index + 1).". {$server}");
        }
        $this->line('');

        $choice = $this->ask('Select server', '1');
        $index = (int) $choice - 1;

        if (! isset($servers[$index])) {
            $this->error('❌ Invalid server selection');

            return null;
        }

        return $servers[$index];
    }

    protected function getAvailableServers(): array
    {
        $deployDir = base_path('.deploy');
        if (! File::exists($deployDir)) {
            $this->error('❌ .deploy directory not found.');
            $this->info('💡 Run: php artisan laravel-deployer:install');

            return [];
        }

        try {
            $envFiles = File::glob($deployDir.'/.env.*');
            $servers = [];

            foreach ($envFiles as $file) {
                $filename = basename($file);
                if (preg_match('/^\.env\.(.+?)(?:\.example)?$/', $filename, $matches)) {
                    if (! str_ends_with($filename, '.example')) {
                        $servers[] = $matches[1];
                    }
                }
            }

            if (empty($servers)) {
                $this->error('❌ No environment files found in .deploy/');
                $this->info('💡 Create .env files in .deploy/ directory (e.g., .env.production, .env.staging)');
            }

            return $servers;
        } catch (\Exception $e) {
            $this->error("❌ Error reading environment files: {$e->getMessage()}");

            return [];
        }
    }

    protected function validateServer(string $serverName): bool
    {
        $servers = $this->getAvailableServers();
        if (! in_array($serverName, $servers)) {
            $this->error("❌ Server '{$serverName}' not found");
            if (! empty($servers)) {
                $this->info('💡 Available servers: '.implode(', ', $servers));
            }

            return false;
        }

        return true;
    }
}
