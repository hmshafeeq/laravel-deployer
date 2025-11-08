<?php

namespace Shaf\LaravelDeployer\Commands;

use Shaf\LaravelDeployer\Actions\Database\BackupDatabaseAction;
use Shaf\LaravelDeployer\Commands\Traits\ManagesServerSelection;

class DatabaseBackupCommand extends BaseDeployerCommand
{
    use ManagesServerSelection;

    protected $signature = 'database:backup
                            {server? : Server name (staging, production, etc.)}
                            {--select : Show available servers and select interactively}';

    protected $description = 'Create database backup on remote server';

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

        return $this->executeWithErrorHandling(
            fn () => $this->performBackup($serverName),
            '✅ Database backup completed successfully!',
            '❌ Database backup failed'
        );
    }

    /**
     * Perform the database backup operation
     *
     * @param string $serverName
     * @return void
     */
    protected function performBackup(string $serverName): void
    {
        $deployer = $this->initDeployer($serverName);

        BackupDatabaseAction::run($deployer);

        $this->info('💡 To download the backup:');
        $this->line("   php artisan database:download {$serverName}");
    }
}
