<?php

namespace Shaf\LaravelDeployer\Commands\Traits;

use Shaf\LaravelDeployer\Services\BackupManager;

/**
 * Trait for managing backup selection in commands
 *
 * This trait provides UI layer functionality for selecting database
 * backups from available backup files. It delegates business logic
 * to the BackupManager service.
 */
trait ManagesBackupSelection
{
    protected ?BackupManager $backupManager = null;

    /**
     * Get backup manager instance
     *
     * @return BackupManager
     */
    protected function getBackupManager(): BackupManager
    {
        return $this->backupManager ??= new BackupManager();
    }

    /**
     * Get selected backup with user interaction
     *
     * This method handles the complete backup selection flow:
     * 1. Check for --latest option (auto-select latest)
     * 2. Check for backup argument (filename or index)
     * 3. Fall back to interactive selection
     *
     * @return string|null Path to selected backup or null if none selected
     */
    protected function getSelectedBackup(): ?string
    {
        // Handle --latest option
        if ($this->option('latest')) {
            return $this->getBackupManager()->getLatestBackup();
        }

        // Handle backup argument
        $backupArg = $this->argument('backup');
        if ($backupArg) {
            $backup = $this->getBackupManager()->findBackup($backupArg);

            if (!$backup) {
                $this->error("❌ Backup not found: {$backupArg}");

                return null;
            }

            return $backup;
        }

        // Interactive selection
        return $this->selectBackupInteractively();
    }

    /**
     * Interactive backup selection (UI layer)
     *
     * Displays a numbered list of available backups and prompts
     * the user to select one.
     *
     * @return string|null Path to selected backup or null if invalid selection
     */
    protected function selectBackupInteractively(): ?string
    {
        $backups = $this->getBackupManager()->getAvailableBackups();

        if (empty($backups)) {
            $this->displayNoBackupsError();

            return null;
        }

        $this->displayBackups($backups);

        $choice = $this->ask(
            'Enter backup number to restore (1-'.count($backups).') or press Enter for latest',
            '1'
        );

        if (!is_numeric($choice) || $choice < 1 || $choice > count($backups)) {
            $this->error("❌ Invalid backup selection: {$choice}");

            return null;
        }

        return $backups[$choice - 1];
    }

    /**
     * Display list of available backups (UI layer)
     *
     * Shows a formatted table of backups with index, filename,
     * size, and date.
     *
     * @param array<string> $backups List of backup file paths
     * @return void
     */
    protected function displayBackups(array $backups): void
    {
        $this->info('📋 Available database backups:');
        $this->line('');

        $backupManager = $this->getBackupManager();

        foreach ($backups as $index => $backup) {
            $metadata = $backupManager->getBackupMetadata($backup);

            $this->line(sprintf(
                '   %d. %s (%s) - %s',
                $index + 1,
                $metadata['name'],
                $metadata['size_formatted'],
                $metadata['date']
            ));
        }

        $this->line('');
    }

    /**
     * Display "no backups found" error message
     *
     * Shows contextual error message based on whether the backups
     * directory exists or not.
     *
     * @return void
     */
    protected function displayNoBackupsError(): void
    {
        if (!$this->getBackupManager()->backupsDirectoryExists()) {
            $this->error('❌ No backups directory found.');
        } else {
            $this->error('❌ No database backups found in .deploy/downloads/backups/ directory.');
        }

        $this->info('💡 Run \'php artisan database:download\' to download backups from server.');
    }
}
