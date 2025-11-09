<?php

namespace Shaf\LaravelDeployer\Actions\HealthCheck;

use Shaf\LaravelDeployer\Support\Abstract\HealthCheckAction;

class CheckDiskSpaceAction extends HealthCheckAction
{
    public function execute(): array
    {
        $this->writeln("run df -h {$this->getDeployPath()} | tail -1");
        $diskUsage = $this->cmd("df -h {$this->getDeployPath()} | tail -1");
        $this->writeln($diskUsage);

        return $this->analyzeDiskSpace($diskUsage);
    }

    /**
     * Analyze disk space usage and check thresholds
     */
    protected function analyzeDiskSpace(string $diskUsage): array
    {
        $diskInfo = preg_split('/\s+/', trim($diskUsage));

        // Handle different df output formats (Linux vs macOS)
        $usedPercentIndex = count($diskInfo) === 6 ? 4 : 3;
        $availableIndex = count($diskInfo) === 6 ? 3 : 2;

        if (!isset($diskInfo[$usedPercentIndex])) {
            throw new \RuntimeException("Unable to parse disk usage information");
        }

        $usedPercent = rtrim($diskInfo[$usedPercentIndex], '%');
        $available = $diskInfo[$availableIndex] ?? 'unknown';

        $this->writeln("💾 Disk Usage: {$diskInfo[$usedPercentIndex]} used, {$available} available");

        $criticalThreshold = config('laravel-deployer.resources.disk.critical_threshold', 90);
        $warningThreshold = config('laravel-deployer.resources.disk.warning_threshold', 80);

        // Check critical threshold
        if ((int) $usedPercent > $criticalThreshold) {
            throw new \RuntimeException("❌ Disk space critical! {$usedPercent}% used. Please free up space before deployment.");
        }

        // Check warning threshold
        if ((int) $usedPercent > $warningThreshold) {
            $this->writeln("⚠️  Warning: Disk usage is high ({$usedPercent}%). Consider cleaning up old releases.", 'comment');
            $status = 'warning';
        } else {
            $this->writeln("✅ Disk space OK");
            $status = 'ok';
        }

        return [
            'status' => $status,
            'used_percent' => (int) $usedPercent,
            'available' => $available,
        ];
    }
}
