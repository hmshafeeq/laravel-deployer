<?php

/**
 * Deployment utility helpers
 *
 * These are simple utility functions for common deployment operations
 * that don't fit into services or actions.
 */

if (!function_exists('deployment_timestamp')) {
    /**
     * Get current deployment timestamp in ISO 8601 format
     */
    function deployment_timestamp(): string
    {
        return date('Y-m-d\TH:i:s+0000');
    }
}

if (!function_exists('release_name_from_timestamp')) {
    /**
     * Generate a release name from a timestamp
     */
    function release_name_from_timestamp(?string $timestamp = null): string
    {
        return date('YmdHis', $timestamp ? strtotime($timestamp) : time());
    }
}

if (!function_exists('format_bytes')) {
    /**
     * Format bytes into human-readable format
     */
    function format_bytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

if (!function_exists('deployment_lock_file')) {
    /**
     * Get the deployment lock file path
     */
    function deployment_lock_file(string $deployPath): string
    {
        return rtrim($deployPath, '/') . '/.dep/deploy.lock';
    }
}

if (!function_exists('normalize_path')) {
    /**
     * Normalize a path by removing trailing slashes
     */
    function normalize_path(string $path): string
    {
        return rtrim($path, '/');
    }
}

if (!function_exists('build_remote_path')) {
    /**
     * Build a remote path from components
     */
    function build_remote_path(string ...$parts): string
    {
        return implode('/', array_map(fn($part) => trim($part, '/'), array_filter($parts)));
    }
}

if (!function_exists('escape_shell_arg_multiple')) {
    /**
     * Escape multiple shell arguments
     */
    function escape_shell_arg_multiple(array $args): array
    {
        return array_map('escapeshellarg', $args);
    }
}

if (!function_exists('is_valid_release_name')) {
    /**
     * Check if a release name is valid (timestamp format)
     */
    function is_valid_release_name(string $name): bool
    {
        return preg_match('/^\d{14}$/', $name) === 1;
    }
}

if (!function_exists('parse_release_timestamp')) {
    /**
     * Parse a release name into a timestamp
     */
    function parse_release_timestamp(string $releaseName): ?int
    {
        if (!is_valid_release_name($releaseName)) {
            return null;
        }

        $year = substr($releaseName, 0, 4);
        $month = substr($releaseName, 4, 2);
        $day = substr($releaseName, 6, 2);
        $hour = substr($releaseName, 8, 2);
        $minute = substr($releaseName, 10, 2);
        $second = substr($releaseName, 12, 2);

        return strtotime("{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}");
    }
}

if (!function_exists('get_release_age')) {
    /**
     * Get the age of a release in human-readable format
     */
    function get_release_age(string $releaseName): string
    {
        $timestamp = parse_release_timestamp($releaseName);
        if (!$timestamp) {
            return 'Unknown';
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return $diff . ' seconds ago';
        } elseif ($diff < 3600) {
            return floor($diff / 60) . ' minutes ago';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . ' hours ago';
        } else {
            return floor($diff / 86400) . ' days ago';
        }
    }
}
