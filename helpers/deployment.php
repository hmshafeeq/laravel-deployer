<?php

/**
 * Deployment utility helpers
 *
 * These are simple utility functions for common deployment operations
 * that don't fit into services or actions.
 */
if (! function_exists('format_duration')) {
    /**
     * Format a duration in seconds to human-readable format
     */
    function format_duration(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000).'ms';
        }

        if ($seconds < 60) {
            return round($seconds, 1).'s';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = round($seconds - ($minutes * 60));

        if ($minutes < 60) {
            return $remainingSeconds > 0
                ? "{$minutes}m {$remainingSeconds}s"
                : "{$minutes}m";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes - ($hours * 60);

        return $remainingMinutes > 0
            ? "{$hours}h {$remainingMinutes}m"
            : "{$hours}h";
    }
}
