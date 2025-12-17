<?php

namespace Shaf\LaravelDeployer\Actions\Database;

use Shaf\LaravelDeployer\Support\Abstract\DatabaseAction;

class CleanupOldBackupsAction extends DatabaseAction
{
    public function execute(): int
    {
        $backupPath = $this->getFullBackupPath();
        $keepCount = config('laravel-deployer.backup.keep', 3);

        $this->writeln("");
        $this->writeln("🧹 Cleaning up old backups (keeping {$keepCount} most recent)...");

        $this->writeln("run cd {$backupPath} && ls -t db_backup_*.sql.gz | tail -n +".($keepCount + 1)." | xargs -r rm -f");
        $this->cmd("cd {$backupPath} && ls -t db_backup_*.sql.gz | tail -n +".($keepCount + 1)." | xargs -r rm -f");

        $remaining = $this->countRemainingBackups($backupPath);

        $this->writeln("✅ Total backups on server: {$remaining}");

        return $remaining;
    }

    protected function countRemainingBackups(string $backupPath): int
    {
        $this->writeln("run cd {$backupPath} && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l");
        $backupCount = (int) trim($this->cmd("cd {$backupPath} && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l"));
        $this->writeln($backupCount);

        return $backupCount;
    }
}
