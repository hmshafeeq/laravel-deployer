<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\CommandService;

/**
 * Database operations action.
 * Handles backup, restore, upload, and download operations.
 */
class DatabaseAction
{
    public function __construct(
        private CommandService $cmd,
        private DeploymentConfig $config
    ) {}

    /**
     * Backup the database
     */
    public function backup(?string $filename = null): string
    {
        $this->cmd->task('database:backup');

        $filename = $filename ?? 'backup-'.date('Y-m-d-His').'.sql';
        $backupPath = "{$this->config->deployPath}/shared/backups";

        // Create backups directory
        $this->cmd->remote("mkdir -p {$backupPath}");

        // Get database credentials from .env
        $releasePath = $this->config->deployPath.'/current';
        $dbName = trim($this->cmd->remote("grep '^DB_DATABASE=' {$releasePath}/.env | cut -d'=' -f2"));
        $dbUser = trim($this->cmd->remote("grep '^DB_USERNAME=' {$releasePath}/.env | cut -d'=' -f2"));
        $dbPass = trim($this->cmd->remote("grep '^DB_PASSWORD=' {$releasePath}/.env | cut -d'=' -f2"));

        // Perform backup
        $backupFile = "{$backupPath}/{$filename}";
        $this->cmd->remote("mysqldump -u{$dbUser} -p{$dbPass} {$dbName} > {$backupFile}");

        $this->cmd->success("Database backed up to: {$backupFile}");

        return $backupFile;
    }

    /**
     * Download database backup to local machine
     */
    public function download(string $remoteFile, string $localPath): void
    {
        $this->cmd->task('database:download');

        $this->cmd->info('Downloading database backup...');

        $scpCommand = "scp {$this->config->remoteUser}@{$this->config->hostname}:{$remoteFile} {$localPath}";
        $this->cmd->local($scpCommand);

        $this->cmd->success("Database backup downloaded to: {$localPath}");
    }

    /**
     * Upload database backup to server
     */
    public function upload(string $localFile, ?string $remotePath = null): string
    {
        $this->cmd->task('database:upload');

        $remotePath = $remotePath ?? "{$this->config->deployPath}/shared/backups";
        $filename = basename($localFile);

        // Create backups directory
        $this->cmd->remote("mkdir -p {$remotePath}");

        $this->cmd->info('Uploading database backup...');

        $scpCommand = "scp {$localFile} {$this->config->remoteUser}@{$this->config->hostname}:{$remotePath}/{$filename}";
        $this->cmd->local($scpCommand);

        $remoteFile = "{$remotePath}/{$filename}";
        $this->cmd->success("Database backup uploaded to: {$remoteFile}");

        return $remoteFile;
    }

    /**
     * Restore database from backup file
     */
    public function restore(string $backupFile): void
    {
        $this->cmd->task('database:restore');

        $this->cmd->warning('⚠️  This will overwrite the current database!');

        // Get database credentials from .env
        $releasePath = $this->config->deployPath.'/current';
        $dbName = trim($this->cmd->remote("grep '^DB_DATABASE=' {$releasePath}/.env | cut -d'=' -f2"));
        $dbUser = trim($this->cmd->remote("grep '^DB_USERNAME=' {$releasePath}/.env | cut -d'=' -f2"));
        $dbPass = trim($this->cmd->remote("grep '^DB_PASSWORD=' {$releasePath}/.env | cut -d'=' -f2"));

        // Restore database
        $this->cmd->remote("mysql -u{$dbUser} -p{$dbPass} {$dbName} < {$backupFile}");

        $this->cmd->success("Database restored from: {$backupFile}");
    }

    /**
     * Backup and download in one operation
     */
    public function backupAndDownload(string $localPath): string
    {
        $remoteFile = $this->backup();
        $filename = basename($remoteFile);
        $localFile = rtrim($localPath, '/').'/'.$filename;

        $this->download($remoteFile, $localFile);

        return $localFile;
    }
}
