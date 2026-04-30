<?php

namespace Shaf\LaravelDeployer\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;

/**
 * Provides local backup file management for commands.
 *
 * Requires the using class to be an Illuminate\Console\Command
 * with 'backup' argument and '--list', '--latest' options in its signature.
 */
trait ManagesLocalBackups
{
    protected array $backups = [];

    protected function getBackupsDirectory(): string
    {
        return base_path('.deploy/downloads/backups');
    }

    protected function loadAvailableBackups(): bool
    {
        $backupsDir = $this->getBackupsDirectory();

        if (! File::exists($backupsDir)) {
            $this->error('No backups directory found.');
            $this->info('Run \'php artisan deployer:db download\' first to download backups.');

            return false;
        }

        $this->backups = $this->findBackupFiles($backupsDir);

        if (empty($this->backups)) {
            $this->error('No database backups found in .deploy/downloads/backups/ directory.');
            $this->info('Run \'php artisan deployer:db download\' to download backups from server.');

            return false;
        }

        return true;
    }

    protected function findBackupFiles(string $backupsDir): array
    {
        // Match both old format (db_backup_*) and new format (backup-*)
        $gzFiles = File::glob($backupsDir.'/*.sql.gz');
        $sqlFiles = File::glob($backupsDir.'/*.sql');
        $files = array_merge($gzFiles, $sqlFiles);

        // Sort by modification time (newest first)
        usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));

        return $files;
    }

    protected function displayBackups(): void
    {
        $this->info('Available database backups:');
        $this->line('');

        foreach ($this->backups as $index => $backup) {
            $name = basename($backup);
            $size = Number::fileSize(filesize($backup));
            $date = date('Y-m-d H:i:s', filemtime($backup));

            $this->line(sprintf('   %d. %s (%s) - %s', $index + 1, $name, $size, $date));
        }

        $this->line('');
    }

    protected function selectBackup(): ?string
    {
        if ($this->option('latest')) {
            return $this->backups[0];
        }

        $backupArg = $this->argument('backup');
        if ($backupArg) {
            return $this->resolveBackupArgument($backupArg);
        }

        return $this->promptForBackup();
    }

    protected function resolveBackupArgument(string $backupArg): ?string
    {
        if (is_numeric($backupArg)) {
            $index = (int) $backupArg - 1;
            if (isset($this->backups[$index])) {
                return $this->backups[$index];
            }
            $this->error("Invalid backup selection: {$backupArg}");

            return null;
        }

        $backupPath = $this->getBackupsDirectory().'/'.$backupArg;
        if (File::exists($backupPath)) {
            return $backupPath;
        }

        $this->error("Backup file not found: {$backupArg}");

        return null;
    }

    protected function promptForBackup(): ?string
    {
        $this->displayBackups();

        $count = count($this->backups);
        $choice = $this->ask(
            "Enter backup number (1-{$count}) or press Enter for latest",
            '1'
        );

        if (! is_numeric($choice) || $choice < 1 || $choice > $count) {
            $this->error("Invalid backup selection: {$choice}");

            return null;
        }

        return $this->backups[$choice - 1];
    }

    protected function getBackupInfo(string $backupPath): array
    {
        return [
            'path' => $backupPath,
            'name' => basename($backupPath),
            'size' => filesize($backupPath),
            'size_formatted' => Number::fileSize(filesize($backupPath)),
            'date' => date('Y-m-d H:i:s', filemtime($backupPath)),
        ];
    }
}
