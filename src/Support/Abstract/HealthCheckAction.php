<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer;

/**
 * Base class for health check actions
 *
 * Provides common functionality for health check operations including
 * threshold validation, result reporting, and status determination.
 */
abstract class HealthCheckAction extends Action
{
    public function __construct(
        protected Deployer $deployer
    ) {}

    /**
     * Check if a value exceeds a threshold
     *
     * @param  float  $value  Current value
     * @param  float  $threshold  Threshold value
     * @param  bool  $higherIsBad  If true, values above threshold are bad
     * @return bool True if threshold is exceeded
     */
    protected function exceedsThreshold(float $value, float $threshold, bool $higherIsBad = true): bool
    {
        return $higherIsBad ? $value > $threshold : $value < $threshold;
    }

    /**
     * Determine health status based on thresholds
     *
     * @param  float  $value  Current value
     * @param  float  $warningThreshold  Warning threshold
     * @param  float  $criticalThreshold  Critical threshold
     * @param  bool  $higherIsBad  If true, higher values are worse
     * @return string 'ok', 'warning', or 'critical'
     */
    protected function determineStatus(
        float $value,
        float $warningThreshold,
        float $criticalThreshold,
        bool $higherIsBad = true
    ): string {
        if ($this->exceedsThreshold($value, $criticalThreshold, $higherIsBad)) {
            return 'critical';
        }

        if ($this->exceedsThreshold($value, $warningThreshold, $higherIsBad)) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Write health check result with appropriate styling
     *
     * @param  string  $check  Name of the check
     * @param  string  $status  Status ('ok', 'warning', 'critical')
     * @param  string  $details  Additional details
     */
    protected function writeHealthCheckResult(string $check, string $status, string $details = ''): void
    {
        $icon = match ($status) {
            'ok' => '✓',
            'warning' => '⚠',
            'critical' => '✗',
            default => '•',
        };

        $style = match ($status) {
            'ok' => 'info',
            'warning' => 'comment',
            'critical' => 'error',
            default => 'plain',
        };

        $message = "{$icon} {$check}";
        if ($details) {
            $message .= ": {$details}";
        }

        $this->writeln($message, $style);
    }

    /**
     * Parse percentage value from string
     *
     * Extracts numeric percentage value from strings like "85%" or "85.5%"
     *
     * @param  string  $value  String containing percentage
     * @return float Percentage as float
     */
    protected function parsePercentage(string $value): float
    {
        preg_match('/(\d+\.?\d*)/', $value, $matches);

        return isset($matches[1]) ? (float) $matches[1] : 0.0;
    }

    /**
     * Get configured health check thresholds from config
     *
     * @param  string  $type  Type of check (disk, memory, etc.)
     * @return array{warning: int, critical: int}
     */
    protected function getThresholds(string $type): array
    {
        $config = config("laravel-deployer.health_check.{$type}_thresholds", []);

        return [
            'warning' => $config['warning'] ?? 80,
            'critical' => $config['critical'] ?? 90,
        ];
    }

    /**
     * Format bytes to human-readable format
     *
     * @param  int  $bytes  Number of bytes
     * @param  int  $precision  Decimal places
     * @return string Formatted string (e.g., "1.5 GB")
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
