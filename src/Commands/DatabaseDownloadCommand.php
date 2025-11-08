<?php

namespace Shaf\LaravelDeployer\Commands;

use Shaf\LaravelDeployer\Actions\Database\DownloadDatabaseBackupAction;
use Shaf\LaravelDeployer\Commands\Traits\ManagesServerSelection;
use Shaf\LaravelDeployer\Services\BackupManager;

class DatabaseDownloadCommand extends BaseDeployerCommand
{
    use ManagesServerSelection;

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
        if (!$serverName) {
            return self::FAILURE;
        }

        $this->info("🌐 Target server: {$serverName}");
        $this->line('');

        // Ensure backups directory exists
        $backupManager = new BackupManager();
        $backupManager->ensureBackupsDirectoryExists();

        return $this->executeWithErrorHandling(
            fn () => $this->performDownload($serverName),
            '✅ Database download completed successfully!',
            '❌ Database download failed'
        );
    }

    /**
     * Perform the database download operation
     *
     * @param string $serverName
     * @return void
     */
    protected function performDownload(string $serverName): void
    {
        $deployer = $this->initDeployer($serverName);

        $backupSelection = $this->argument('backup');
        $method = $this->argument('method');

        DownloadDatabaseBackupAction::run($deployer, $backupSelection, $method);

        $this->info('💡 To restore the backup:');
        $this->line('   php artisan database:restore');
        $this->line('');
        $this->info('📁 Downloaded backups are in: ./.deploy/downloads/backups/');
    }
}
