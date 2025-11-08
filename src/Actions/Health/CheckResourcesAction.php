<?php

namespace Shaf\LaravelDeployer\Actions\Health;

use Shaf\LaravelDeployer\Actions\AbstractAction;

class CheckResourcesAction extends AbstractAction
{
    public function execute(): void
    {
        $this->deployer->writeln("🔍 Checking server resources...");

        // Check disk space
        $this->deployer->writeln("run df -h {$this->deployer->getDeployPath()} | tail -1");
        $diskUsage = $this->deployer->run("df -h {$this->deployer->getDeployPath()} | tail -1");
        $this->deployer->writeln($diskUsage);

        $diskInfo = preg_split('/\s+/', trim($diskUsage));

        // Handle different df output formats (Linux vs macOS)
        $usedPercentIndex = count($diskInfo) === 6 ? 4 : 3;
        $availableIndex = count($diskInfo) === 6 ? 3 : 2;

        if (isset($diskInfo[$usedPercentIndex])) {
            $usedPercent = rtrim($diskInfo[$usedPercentIndex], '%');
            $available = $diskInfo[$availableIndex] ?? 'unknown';

            $this->deployer->writeln("💾 Disk Usage: {$diskInfo[$usedPercentIndex]} used, {$available} available");

            if ((int) $usedPercent > 90) {
                throw new \RuntimeException("❌ Disk space critical! {$usedPercent}% used. Please free up space before deployment.");
            }

            if ((int) $usedPercent > 80) {
                $this->deployer->writeln("⚠️  Warning: Disk usage is high ({$usedPercent}%). Consider cleaning up old releases.", 'comment');
            } else {
                $this->deployer->writeln("✅ Disk space OK");
            }
        }

        // Check memory usage
        $this->deployer->writeln("run free -h | grep -E \"^Mem:|^Swap:\" || echo \"Memory info unavailable\"");
        $memInfo = $this->deployer->run('free -h | grep -E "^Mem:|^Swap:" || echo "Memory info unavailable"');

        if (!str_contains($memInfo, 'unavailable')) {
            $lines = explode("\n", trim($memInfo));
            foreach ($lines as $line) {
                $this->deployer->writeln($line);
                if (str_starts_with($line, 'Mem:')) {
                    $memParts = preg_split('/\s+/', $line);
                    $this->deployer->writeln("🧠 Memory: {$memParts[2]} used / {$memParts[1]} total ({$memParts[3]} available)");
                }
                if (str_starts_with($line, 'Swap:')) {
                    $swapParts = preg_split('/\s+/', $line);
                    if ($swapParts[1] !== '0B') {
                        $this->deployer->writeln("💾 Swap: {$swapParts[2]} used / {$swapParts[1]} total");
                    }
                }
            }
        }

        $this->deployer->writeln("");
    }

    public function getName(): string
    {
        return 'health:check_resources';
    }
}
