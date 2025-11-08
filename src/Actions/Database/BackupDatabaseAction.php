<?php

namespace Shaf\LaravelDeployer\Actions\Database;

class BackupDatabaseAction extends AbstractDatabaseAction
{
    public function execute(): void
    {
        $timestamp = date('Y-m-d_H-i-s');
        $deployPath = $this->deployer->getDeployPath();
        $backupFile = "{$deployPath}/shared/backups/db_backup_{$timestamp}.sql.gz";

        $this->deployer->writeln("run mkdir -p {$deployPath}/shared/backups");
        $this->deployer->run("mkdir -p {$deployPath}/shared/backups");

        $config = $this->databaseService->getDatabaseConfigWithFile();

        try {
            $this->deployer->writeln("💾 Starting database backup...");
            $this->deployer->writeln("📊 Database: {$config['database']}");
            $this->deployer->writeln("🏠 Host: {$config['host']}");
            $this->deployer->writeln("");
            $this->deployer->writeln("⏳ This may take a while for large databases...");

            // Run mysqldump with timeout and proper error handling
            $dumpCommand = "timeout 1800 mysqldump --defaults-file={$config['config_file']} --single-transaction --routines --triggers {$config['database']} 2>&1";
            $compressCommand = "gzip -8 > {$backupFile}";

            $this->deployer->writeln("run {$dumpCommand} | {$compressCommand}; echo \$?");
            $result = $this->deployer->run("{$dumpCommand} | {$compressCommand}; echo \$?");
            $exitCode = (int) trim($result);

            if ($exitCode !== 0) {
                throw new \RuntimeException("mysqldump failed with exit code: {$exitCode}");
            }

            // Verify backup file was created and has content
            $this->deployer->writeln("run test -f {$backupFile} && echo 'OK' || echo 'FAIL'");
            $fileExists = trim($this->deployer->run("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
            if (!empty($fileExists)) {
                $this->deployer->writeln($fileExists);
            }

            if ($fileExists !== 'OK') {
                throw new \RuntimeException("Backup file was not created: {$backupFile}");
            }

            $this->deployer->writeln("run stat -c%s {$backupFile} 2>/dev/null || stat -f%z {$backupFile} 2>/dev/null || echo 0");
            $fileSize = (int) trim($this->deployer->run("stat -c%s {$backupFile} 2>/dev/null || stat -f%z {$backupFile} 2>/dev/null || echo 0"));
            $this->deployer->writeln($fileSize);

            if ($fileSize < 100) {
                throw new \RuntimeException("Backup file is too small ({$fileSize} bytes), backup likely failed");
            }

            $this->deployer->writeln("");
            $this->deployer->writeln("✅ Database backup completed successfully!");

            $this->deployer->writeln("run ls -lh {$backupFile} | awk '{print \$5}'");
            $fileSizeHuman = trim($this->deployer->run("ls -lh {$backupFile} | awk '{print \$5}'"));
            $this->deployer->writeln($fileSizeHuman);

            $this->deployer->writeln("📁 Location: {$backupFile}");
            $this->deployer->writeln("📊 Size: {$fileSizeHuman}");

            // Clean up old backups (keep only 3 most recent)
            $this->deployer->writeln("");
            $this->deployer->writeln("🧹 Cleaning up old backups (keeping 3 most recent)...");

            $this->deployer->writeln("run cd {$deployPath}/shared/backups && ls -t db_backup_*.sql.gz | tail -n +4 | xargs -r rm -f");
            $this->deployer->run("cd {$deployPath}/shared/backups && ls -t db_backup_*.sql.gz | tail -n +4 | xargs -r rm -f");

            $this->deployer->writeln("run cd {$deployPath}/shared/backups && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l");
            $backupCount = (int) trim($this->deployer->run("cd {$deployPath}/shared/backups && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l"));
            $this->deployer->writeln($backupCount);

            $this->deployer->writeln("✅ Total backups on server: {$backupCount}");

        } catch (\Exception $e) {
            $this->deployer->writeln("");
            $this->deployer->writeln("❌ Backup failed: " . $e->getMessage(), 'error');

            // Clean up failed backup file if it exists
            $fileExists = trim($this->deployer->run("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
            if ($fileExists === 'OK') {
                $this->deployer->run("rm -f {$backupFile}");
                $this->deployer->writeln("🧹 Cleaned up failed backup file");
            }

            throw $e;
        } finally {
            // Always clean up config file
            $this->deployer->writeln("run rm -f {$config['config_file']} 2>/dev/null || true");
            $this->deployer->run("rm -f {$config['config_file']} 2>/dev/null || true");
        }
    }

    public function getName(): string
    {
        return 'database:backup';
    }
}
