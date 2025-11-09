<?php

namespace Shaf\LaravelDeployer\Commands;

use Shaf\LaravelDeployer\Actions\Database\DownloadDatabaseBackupAction;
use Shaf\LaravelDeployer\Commands\Traits\ManagesEnvironmentSelection;
use Shaf\LaravelDeployer\Services\BackupManager;

class DatabaseDownloadCommand extends BaseDeployerCommand
{
    use ManagesEnvironmentSelection;

    protected $signature = 'database:download
                            {environment? : Environment name (staging, production, etc.)}
                            {backup? : Backup selection (latest, 1-10, or filename)}
                            {method? : Download method (rsync or scp)}
                            {--select : Show available environments and select interactively}';

    protected $description = 'Download database backup from remote environment';

    public function handle(): int
    {
        $this->info('📥 Database Download');
        $this->line('');

        $environment = $this->getEnvironmentName();
        if (!$environment) {
            return self::FAILURE;
        }

        $this->info("🌐 Target environment: {$environment}");
        $this->line('');

        // Ensure backups directory exists
        $backupManager = new BackupManager();
        $backupManager->ensureBackupsDirectoryExists();

        return $this->executeWithErrorHandling(
            fn () => $this->performDownload($environment),
            '✅ Database download completed successfully!',
            '❌ Database download failed'
        );
    }

    /**
     * Perform the database download operation
     *
     * @param string $environment
     * @return void
     */
    protected function performDownload(string $environment): void
    {
        $deployer = $this->initDeployer($environment);

        $backupSelection = $this->argument('backup');
        $method = $this->argument('method');

        DownloadDatabaseBackupAction::run($deployer, $backupSelection, $method);

        $this->info('💡 To restore the backup:');
        $this->line('   php artisan database:restore');
        $this->line('');
        $this->info('📁 Downloaded backups are in: ./.deploy/downloads/backups/');
    }
}
