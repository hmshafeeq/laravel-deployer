<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Actions\DatabaseAction;
use Shaf\LaravelDeployer\Concerns\SelectsServer;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;

class DatabaseBackupCommand extends Command
{
    use SelectsServer;

    protected $signature = 'database:backup
                            {server? : Server name (staging, production, etc.)}
                            {--select : Show available servers and select interactively}';

    protected $description = 'Create database backup on remote server';

    public function handle(): int
    {
        $this->info('💾 Database Backup');
        $this->line('');

        $serverName = $this->getServerName();
        if (! $serverName) {
            return self::FAILURE;
        }

        $this->info("🌐 Target server: {$serverName}");
        $this->line('');

        try {
            $config = ConfigService::load($serverName, base_path(), $this->output);
            $cmdService = new CommandService($config, $this->output);
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
}
