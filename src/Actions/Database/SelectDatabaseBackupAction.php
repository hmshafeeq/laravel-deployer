<?php

namespace Shaf\LaravelDeployer\Actions\Database;

use Shaf\LaravelDeployer\Support\Abstract\DatabaseAction;
use Shaf\LaravelDeployer\ValueObjects\BackupInfo;

class SelectDatabaseBackupAction extends DatabaseAction
{
    public function execute(?string $selection = null): BackupInfo
    {
        $backupPath = $this->getFullBackupPath();

        $backups = $this->listAvailableBackups($backupPath);

        if (empty($backups)) {
            throw new \RuntimeException('No database backups found on server');
        }

        $choiceIndex = $this->determineBackupChoice($selection, $backups);

        if ($choiceIndex < 0 || $choiceIndex >= count($backups)) {
            throw new \RuntimeException('Invalid backup selection');
        }

        $parts = preg_split('/\s+/', $backups[$choiceIndex]);

        return new BackupInfo(
            path: $parts[8],
            name: basename($parts[8]),
            size: $parts[4]
        );
    }

    protected function listAvailableBackups(string $backupPath): array
    {
        $backupList = $this->cmd("ls -lt {$backupPath}/db_backup_*.sql.gz 2>/dev/null || echo \"\"");

        if (empty($backupList)) {
            return [];
        }

        $backups = $this->cmd("ls -lht {$backupPath}/db_backup_*.sql.gz | head -10");

        return array_filter(array_map('trim', explode("\n", trim($backups))));
    }

    protected function determineBackupChoice(?string $selection, array $backups): int
    {
        if ($selection !== null) {
            return $this->parseSelection($selection, $backups);
        }

        return $this->displayInteractiveSelection($backups);
    }

    protected function parseSelection(string $selection, array $backups): int
    {
        if (strtolower($selection) === 'latest') {
            if (isset($backups[0])) {
                $parts = preg_split('/\s+/', $backups[0]);
                $filename = basename($parts[8]);
                $this->writeln("📋 Using latest backup: {$filename}");
            }
            return 0;
        }

        if (is_numeric($selection)) {
            $choiceIndex = (int) $selection - 1;
            $this->writeln("📋 Using backup #{$selection}: " . basename($backups[$choiceIndex]));
            return $choiceIndex;
        }

        throw new \RuntimeException("Invalid backup selection: {$selection}");
    }

    protected function displayInteractiveSelection(array $backups): int
    {
        $this->writeln("📋 Available database backups:");
        $this->writeln("");

        foreach ($backups as $index => $line) {
            $parts = preg_split('/\s+/', $line);
            $size = $parts[4];
            $filename = basename($parts[8]);
            $this->writeln("   " . ($index + 1) . ". {$filename} ({$size})");
        }

        $this->writeln("");
        $this->writeln("Using latest backup (automatic selection)");

        return 0;
    }
}
