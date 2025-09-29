<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

class DatabaseBackupCommand extends Command
{
    protected $signature = 'database:backup 
                            {server? : Server name (staging, production, etc.)}
                            {--select : Show available servers and select interactively}';

    protected $description = 'Create database backup on remote server (wrapper for deployer)';

    public function handle(): int
    {
        $this->info('💾 Database Backup');
        $this->line('');

        $serverName = $this->getServerName();
        if (!$serverName) {
            return self::FAILURE;
        }

        $this->info("🌐 Target server: {$serverName}");
        $this->line('');

        // Run deployer command
        $command = sprintf(
            'php vendor/bin/dep database:backup %s',
            escapeshellarg($serverName)
        );

        $this->info('🚀 Running: ' . $command);
        $this->line('');

        // Run with real-time output streaming
        $result = Process::timeout(3600)
            ->path(base_path())
            ->run($command, function ($type, $buffer) {
                echo $buffer;
            });

        if ($result->successful()) {
            $this->line('');
            $this->info('✅ Database backup completed successfully!');
            $this->info('💡 To download the backup:');
            $this->line("   php artisan database:download {$serverName}");
            return self::SUCCESS;
        } else {
            $this->line('');
            $this->error('❌ Database backup failed');
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
            if (!$this->validateServer($serverName)) {
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
            $this->line("   " . ($index + 1) . ". {$server}");
        }
        $this->line('');

        $choice = $this->ask('Select server', '1');
        $index = (int) $choice - 1;

        if (!isset($servers[$index])) {
            $this->error('❌ Invalid server selection');
            return null;
        }

        return $servers[$index];
    }

    protected function getAvailableServers(): array
    {
        $hostsFile = base_path('.deploy/hosts.json');
        if (!File::exists($hostsFile)) {
            $this->error('❌ .deploy/hosts.json not found.');
            $this->info('💡 Run: php artisan laravel-deployer:install');
            return [];
        }

        try {
            $hosts = json_decode(File::get($hostsFile), true);
            return array_keys($hosts);
        } catch (\Exception $e) {
            $this->error("❌ Error reading hosts.json: {$e->getMessage()}");
            return [];
        }
    }

    protected function validateServer(string $serverName): bool
    {
        $servers = $this->getAvailableServers();
        if (!in_array($serverName, $servers)) {
            $this->error("❌ Server '{$serverName}' not found in hosts.json");
            if (!empty($servers)) {
                $this->info('Available servers: ' . implode(', ', $servers));
            }
            return false;
        }
        return true;
    }
}