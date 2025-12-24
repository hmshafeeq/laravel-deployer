<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Shaf\LaravelDeployer\Actions\DatabaseAction;
use Shaf\LaravelDeployer\Concerns\SelectsServer;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;

class DatabaseDownloadCommand extends Command
{
    use SelectsServer;

    protected $signature = 'database:download
                            {server? : Server name (staging, production, etc.)}
                            {--latest : Download the latest backup}
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

        $backupsDir = base_path('.deploy/downloads/backups');
        if (! File::exists($backupsDir)) {
            File::makeDirectory($backupsDir, 0755, true);
            $this->info("📁 Created backups directory: {$backupsDir}");
        }

        try {
            $config = ConfigService::load($serverName, base_path(), $this->output);
            $cmdService = new CommandService($config, $this->output);
            $database = new DatabaseAction($cmdService, $config);

            $this->info('Creating backup on server...');
            $remoteFile = $database->backup();

            $this->line('');
            $this->info('Downloading backup...');
            $localFile = $backupsDir.'/'.basename($remoteFile);
            $database->download($remoteFile, $localFile);

            $this->line('');
            $this->info('✅ Database download completed successfully!');
            $this->info("📁 Downloaded to: {$localFile}");
            $this->info('💡 To restore the backup:');
            $this->line('   php artisan database:restore');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->line('');
            $this->error('❌ Database download failed');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
