<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\CommandService;

/**
 * Database operations action.
 * Handles backup, restore, upload, and download operations.
 */
class DatabaseAction extends Action
{
    public function __construct(
        CommandService $cmd,
        DeploymentConfig $config
    ) {
        parent::__construct($cmd, $config);
    }

    /**
     * Backup the database (gzipped)
     */
    public function backup(?string $filename = null): string
    {
        $this->cmd->task('database:backup');

        $filename = $filename ?? 'backup-'.date('Y-m-d-His').'.sql.gz';
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
            // Perform backup using config file, pipe through gzip for compression
            $backupFile = "{$backupPath}/{$filename}";
            $this->cmd->remote("mysqldump --defaults-extra-file={$tempConfig} --single-transaction {$dbCredentials['database']} | gzip > {$backupFile}");
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

        // Use rsync for faster transfers with progress display
        // -h = human readable, -P = progress + partial (allows resume)
        // Disable SSH compression since .sql.gz is already compressed
        $rsyncCommand = "rsync -hP -e 'ssh -o Compression=no' {$this->config->remoteUser}@{$this->config->hostname}:{$remoteFile} {$localPath}";
        $this->cmd->local($rsyncCommand);

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
     * List available backups on the remote server
     *
     * @return array<int, array{name: string, path: string, size: string, date: string}>
     */
    public function listRemoteBackups(): array
    {
        $backupPath = "{$this->config->deployPath}/shared/backups";

        // Get list of backup files sorted by modification time (newest first)
        // Using -t flag to sort by mtime on server, avoiding strtotime year ambiguity
        $output = $this->cmd->remote("ls -lht {$backupPath}/*.sql {$backupPath}/*.sql.gz 2>/dev/null || true");

        if (empty(trim($output))) {
            return [];
        }

        $backups = [];
        $lines = array_filter(explode("\n", trim($output)));

        foreach ($lines as $line) {
            // Parse ls -lh output: -rw-r--r-- 1 user group 123M Jan  4 12:34 filename.sql
            if (preg_match('/\S+\s+\d+\s+\S+\s+\S+\s+(\S+)\s+(\S+\s+\d+\s+[\d:]+)\s+(.+)$/', $line, $matches)) {
                $size = $matches[1];
                $date = $matches[2];
                $path = $matches[3];
                $name = basename($path);

                $backups[] = [
                    'name' => $name,
                    'path' => $path,
                    'size' => $size,
                    'date' => $date,
                ];
            }
        }

        // Already sorted by ls -t (newest first)
        return $backups;
    }

    /**
     * Get the latest backup on the remote server
     */
    public function getLatestRemoteBackup(): ?array
    {
        $backups = $this->listRemoteBackups();

        return $backups[0] ?? null;
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

        $configContent = "[client]\n";
        $configContent .= "host={$credentials['host']}\n";
        $configContent .= "user={$credentials['username']}\n";
        $configContent .= "password={$credentials['password']}\n";

        return $this->writeSecureFile($tempConfig, $configContent);
    }
}
