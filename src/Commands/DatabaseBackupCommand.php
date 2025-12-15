<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Actions\DatabaseAction;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;

class DatabaseBackupCommand extends BaseDeployerCommand
{
    use ManagesEnvironmentSelection;

    protected $signature = 'database:backup
                            {environment? : Environment name (staging, production, etc.)}
                            {--select : Show available environments and select interactively}';

    protected $description = 'Create database backup on remote environment';

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

        try {
            // Load configuration and initialize services
            $config = ConfigService::load($serverName, base_path());
            $cmdService = new CommandService($config, $this->output);

            // Execute backup
            $database = new DatabaseAction($cmdService, $config);
            $backupFile = $database->backup();

            $this->line('');
            $this->info('✅ Database backup completed successfully!');
            $this->info("📁 Backup file: {$backupFile}");
            $this->info('💡 To download the backup:');
            $this->line("   php artisan database:download {$serverName}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->line('');
            $this->error('❌ Database backup failed');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function getServerName(): ?string
    {
        $serverName = $this->argument('server');

        if ($serverName) {
            return $serverName;
        }

        if ($this->option('select')) {
            $configService = new ConfigService(base_path());
            $servers = $configService->getAvailableEnvironments();

            if (empty($servers)) {
                $this->error('No servers configured in deploy.yaml');
                return null;
            }

            $serverName = $this->choice('Select a server', $servers);
            return $serverName;
        }

        $this->error('Please provide a server name or use --select option');
        return null;
    }
}
