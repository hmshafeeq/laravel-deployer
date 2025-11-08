<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Shaf\LaravelDeployer\Actions\Database\BackupDatabaseAction;
use Shaf\LaravelDeployer\Actions\Database\DownloadDatabaseBackupAction;
use Shaf\LaravelDeployer\Deployer;
use Symfony\Component\Yaml\Yaml;

class DatabaseDownloadCommand extends Command
{
    protected $signature = 'database:download
                            {server? : Server name (staging, production, etc.)}
                            {backup? : Backup selection (latest, 1-10, or filename)}
                            {method? : Download method (rsync or scp)}
                            {--select : Show available servers and select interactively}';

    protected $description = 'Download database backup from remote server';

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
        $backupsDir = base_path('.deploy/downloads/backups');
        if (! File::exists($backupsDir)) {
            File::makeDirectory($backupsDir, 0755, true);
            $this->info("📁 Created backups directory: {$backupsDir}");
        }

        try {
            // Load configuration
            $config = $this->loadConfiguration($serverName);

            // Create deployer instance
            $deployer = new Deployer($serverName, $config);

            // Load environment variables
            $deployer->loadEnvironment();

            // Create database tasks
            $databaseTasks = new DatabaseTasks($deployer);

            // Get arguments
            $backupSelection = $this->argument('backup');
            $method = $this->argument('method');

            // Run download
            $databaseTasks->download($backupSelection, $method);

            $this->line('');
            $this->info('✅ Database download completed successfully!');
            $this->info('💡 To restore the backup:');
            $this->line('   php artisan database:restore');
            $this->line('');
            $this->info('📁 Downloaded backups are in: ./.deploy/downloads/backups/');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->line('');
            $this->error('❌ Database download failed');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    protected function loadConfiguration(string $environment): array
    {
        $yamlPath = base_path('deploy.yaml');

        if (!file_exists($yamlPath)) {
            throw new \RuntimeException("Configuration file not found: {$yamlPath}");
        }

        $yaml = Yaml::parseFile($yamlPath);

        // Load environment-specific configuration
        $hostConfig = $yaml['hosts'][$environment] ?? [];

        return [
            'environment' => $environment,
            'hostname' => $hostConfig['hostname'] ?? 'localhost',
            'remote_user' => $hostConfig['remote_user'] ?? 'deploy',
            'deploy_path' => $hostConfig['deploy_path'] ?? '/var/www/app',
            'branch' => $hostConfig['branch'] ?? 'main',
            'local' => $hostConfig['local'] ?? false,
            'application' => $yaml['config']['application'] ?? 'Application',
        ];
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
