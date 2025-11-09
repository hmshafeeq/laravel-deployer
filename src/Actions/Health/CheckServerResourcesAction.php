<?php

namespace Shaf\LaravelDeployer\Actions\Health;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Services\OutputService;

class CheckServerResourcesAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output
    ) {
    }

    public function execute(): void
    {
        $this->output->info("Checking server resources...");

        // Check disk space
        $this->checkDiskSpace();

        // Check memory
        $this->checkMemory();

        $this->output->success("Server resources check passed");
    }

    protected function checkDiskSpace(): void
    {
        $diskUsage = $this->executor->execute("df -h / | tail -1 | awk '{print $5}' | sed 's/%//'");
        $usagePercent = (int) trim($diskUsage);

        $this->output->info("Disk usage: {$usagePercent}%");

        if ($usagePercent > 90) {
            throw new \RuntimeException("Disk usage is critically high: {$usagePercent}%");
        }

        if ($usagePercent > 80) {
            $this->output->warn("Disk usage is high: {$usagePercent}%");
        }
    }

    protected function checkMemory(): void
    {
        $memInfo = $this->executor->execute("free | grep Mem | awk '{print $3/$2 * 100.0}'");
        $memoryPercent = (int) round((float) trim($memInfo));

        $this->output->info("Memory usage: {$memoryPercent}%");

        if ($memoryPercent > 95) {
            throw new \RuntimeException("Memory usage is critically high: {$memoryPercent}%");
        }

        if ($memoryPercent > 85) {
            $this->output->warn("Memory usage is high: {$memoryPercent}%");
        }
    }
}
