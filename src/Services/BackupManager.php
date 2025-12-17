<?php

namespace Shaf\LaravelDeployer\Services;

use Illuminate\Support\Facades\File;
use Shaf\LaravelDeployer\Support\FileHelper;

class BackupManager
{
    protected string $backupsDirectory;

    /**
     * Create a new BackupManager instance
     *
     * @param string|null $backupsDirectory Custom backups directory path (optional)
     */
    public function __construct(?string $backupsDirectory = null)
    {
        $this->backupsDirectory = $backupsDirectory ?? base_path('.deploy/downloads/backups');
    }

    /**
     * Get list of available backups sorted by time (newest first)
     *
     * @return array<string> List of backup file paths
     */
    public function getAvailableBackups(): array
    {
        $files = File::glob($this->backupsDirectory.'/db_backup_*.sql.gz');

        // Sort by modification time (newest first)
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $files;
    }

    /**
     * Get the latest backup
     *
     * @return string|null Path to latest backup or null if none found
     */
    public function getLatestBackup(): ?string
    {
        $backups = $this->getAvailableBackups();

        return $backups[0] ?? null;
    }

    /**
     * Find backup by filename or index
     *
     * @param string|int $identifier Backup filename or index (1-based)
     * @return string|null Path to backup or null if not found
     */
    public function findBackup(string|int $identifier): ?string
    {
        $backups = $this->getAvailableBackups();

        // If numeric, treat as index (1-based)
        if (is_numeric($identifier)) {
            $index = (int) $identifier - 1;

            return $backups[$index] ?? null;
        }

        // Treat as filename - check if it's a full path or just filename
        if (File::exists($identifier)) {
            return $identifier;
        }

        // Try as filename in backups directory
        $backupPath = $this->backupsDirectory.'/'.$identifier;

        return File::exists($backupPath) ? $backupPath : null;
    }

    /**
     * Check if backups directory exists
     *
     * @return bool True if directory exists, false otherwise
     */
    public function backupsDirectoryExists(): bool
    {
        return File::exists($this->backupsDirectory);
    }

    /**
     * Get backups directory path
     *
     * @return string Path to backups directory
     */
    public function getBackupsDirectory(): string
    {
        return $this->backupsDirectory;
    }

    /**
     * Get backup metadata
     *
     * @param string $backupPath Path to backup file
     * @return array{name: string, size: int, size_formatted: string, date: string, timestamp: int}
     */
    public function getBackupMetadata(string $backupPath): array
    {
        return [
            'name' => basename($backupPath),
            'size' => filesize($backupPath),
            'size_formatted' => FileHelper::formatFileSize(filesize($backupPath)),
            'date' => date('Y-m-d H:i:s', filemtime($backupPath)),
            'timestamp' => filemtime($backupPath),
        ];
    }

    /**
     * Ensure backups directory exists, creating it if necessary
     *
     * @param int $permissions Directory permissions (default: 0755)
     * @return bool True if directory exists or was created successfully
     */
    public function ensureBackupsDirectoryExists(int $permissions = 0755): bool
    {
        if ($this->backupsDirectoryExists()) {
            return true;
        }

        try {
            File::makeDirectory($this->backupsDirectory, $permissions, true);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
