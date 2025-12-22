<?php

namespace Shaf\LaravelDeployer\Actions\Database;

use Shaf\LaravelDeployer\Support\Abstract\DatabaseAction;
use Shaf\LaravelDeployer\ValueObjects\DatabaseConfig;

class BackupDatabaseAction extends DatabaseAction
{
    public function execute(): string
    {
        $backupFile = $this->prepareBackupFile();
        $config = $this->configExtractor->extract($this->deployer->getCurrentPath());

        try {
            $this->performBackup($backupFile, $config);

            // Use other actions for verification and cleanup
            VerifyBackupAction::run($this->deployer, $backupFile);
            CleanupOldBackupsAction::run($this->deployer);

            return $backupFile;
        } catch (\Exception $e) {
            $this->handleFailure($backupFile, $e);
            throw $e;
        } finally {
            $config->cleanupConfigFile();
        }
    }

    protected function prepareBackupFile(): string
    {
        $timestamp = date('Y-m-d_H-i-s');
        $backupPath = $this->getFullBackupPath();

        $this->writeln("run mkdir -p {$backupPath}");
        $this->cmd("mkdir -p {$backupPath}");

        return "{$backupPath}/db_backup_{$timestamp}.sql.gz";
    }

    protected function performBackup(string $backupFile, DatabaseConfig $config): void
    {
        $this->writeln('💾 Starting database backup...');
        $this->writeln("📊 Database: {$config->database}");
        $this->writeln("🏠 Host: {$config->host}");
        $this->writeln('');
        $this->writeln('⏳ This may take a while for large databases...');

        $timeout = config('laravel-deployer.backup.timeout', 1800);
        $compression = config('laravel-deployer.backup.compression_level', 8);
        $configFile = $config->getConfigFile();

        $dumpCommand = "timeout {$timeout} mysqldump --defaults-file={$configFile} --single-transaction --routines --triggers {$config->database} 2>&1";
        $compressCommand = "gzip -{$compression} > {$backupFile}";

        $this->writeln("run {$dumpCommand} | {$compressCommand}; echo \$?");
        $result = $this->cmd("{$dumpCommand} | {$compressCommand}; echo \$?");
        $exitCode = (int) trim($result);

        if ($exitCode !== 0) {
            throw new \RuntimeException("mysqldump failed with exit code: {$exitCode}");
        }
    }

    protected function handleFailure(string $backupFile, \Exception $e): void
    {
        $this->writeln('');
        $this->writeln('❌ Backup failed: '.$e->getMessage(), 'error');

        $fileExists = trim($this->cmd("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
        if ($fileExists === 'OK') {
            $this->cmd("rm -f {$backupFile}");
            $this->writeln('🧹 Cleaned up failed backup file');
        }
    }
}
