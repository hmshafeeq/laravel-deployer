<?php

namespace Shaf\LaravelDeployer\Support;

class FileHelper
{
    /**
     * Format file size in human-readable format
     *
     * Converts bytes to the most appropriate unit (B, KB, MB, GB)
     * and returns a formatted string with 2 decimal places.
     *
     * @param int $bytes File size in bytes
     * @return string Formatted file size (e.g., "1.23 MB")
     */
    public static function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2).$units[$unitIndex];
    }
}
