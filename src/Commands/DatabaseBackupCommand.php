<?php

namespace Shaf\LaravelDeployer\Commands;

use Shaf\LaravelDeployer\Actions\Database\BackupDatabaseAction;
use Shaf\LaravelDeployer\Commands\Traits\ManagesEnvironmentSelection;

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

        $environment = $this->getEnvironmentName();
        if (!$environment) {
            return self::FAILURE;
        }

        $this->info("🌐 Target environment: {$environment}");
        $this->line('');

        return $this->executeWithErrorHandling(
            fn () => $this->performBackup($environment),
            '✅ Database backup completed successfully!',
            '❌ Database backup failed'
        );
    }

    /**
     * Perform the database backup operation
     *
     * @param string $environment
     * @return void
     */
    protected function performBackup(string $environment): void
    {
        $deployer = $this->initDeployer($environment);

        BackupDatabaseAction::run($deployer);

        $this->info('💡 To download the backup:');
        $this->line("   php artisan database:download {$environment}");
    }
}
