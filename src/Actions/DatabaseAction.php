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

        $this->cmd->info('Creating database backup...');

        // Get database credentials from .env
        $releasePath = $this->config->deployPath.'/current';
        $dbCredentials = $this->getRemoteDatabaseCredentials($releasePath);

        // Create temp MySQL config file on server (avoids password in command line)
        $tempConfig = $this->createRemoteMysqlConfig($dbCredentials);

        try {
            // Perform backup using config file (password not visible in logs)
            $backupFile = "{$backupPath}/{$filename}";
            $this->cmd->remote("mysqldump --defaults-extra-file={$tempConfig} {$dbCredentials['database']} > {$backupFile}");
        } finally {
            // Clean up temp config file
            $this->cmd->remote("rm -f {$tempConfig}");
        }

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

        $this->cmd->info('Restoring database...');

        // Get database credentials from .env
        $releasePath = $this->config->deployPath.'/current';
        $dbCredentials = $this->getRemoteDatabaseCredentials($releasePath);

        // Create temp MySQL config file on server (avoids password in command line)
        $tempConfig = $this->createRemoteMysqlConfig($dbCredentials);

        try {
            // Restore database using config file (password not visible in logs)
            $this->cmd->remote("mysql --defaults-extra-file={$tempConfig} {$dbCredentials['database']} < {$backupFile}");
        } finally {
            // Clean up temp config file
            $this->cmd->remote("rm -f {$tempConfig}");
        }

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

    /**
     * Get database credentials from remote .env file
     *
     * @return array{host: string, database: string, username: string, password: string}
     */
    private function getRemoteDatabaseCredentials(string $releasePath): array
    {
        $envFile = "{$releasePath}/.env";

        return [
            'host' => trim($this->cmd->remote("grep '^DB_HOST=' {$envFile} | cut -d'=' -f2")) ?: 'localhost',
            'database' => trim($this->cmd->remote("grep '^DB_DATABASE=' {$envFile} | cut -d'=' -f2")),
            'username' => trim($this->cmd->remote("grep '^DB_USERNAME=' {$envFile} | cut -d'=' -f2")),
            'password' => trim($this->cmd->remote("grep '^DB_PASSWORD=' {$envFile} | cut -d'=' -f2")),
        ];
    }

    /**
     * Create a temporary MySQL config file on the remote server.
     * This avoids passing passwords on the command line (visible in logs/process list).
     */
    private function createRemoteMysqlConfig(array $credentials): string
    {
        $tempConfig = '/tmp/mysql_deployer_'.uniqid().'.cnf';

        // Create config file with proper escaping
        $configContent = "[client]\n";
        $configContent .= "host={$credentials['host']}\n";
        $configContent .= "user={$credentials['username']}\n";
        $configContent .= "password={$credentials['password']}\n";

        // Write config file and set secure permissions
        $escapedContent = escapeshellarg($configContent);
        $this->cmd->remote("echo {$escapedContent} > {$tempConfig} && chmod 600 {$tempConfig}");

        return $tempConfig;
    }
}
