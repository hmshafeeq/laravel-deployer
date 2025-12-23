<?php

namespace Shaf\LaravelDeployer\Actions\HealthCheck;

use Shaf\LaravelDeployer\Support\Abstract\HealthCheckAction;

class CheckMemoryUsageAction extends HealthCheckAction
{
    public function execute(): array
    {
        $this->writeln('run free -h | grep -E "^Mem:|^Swap:" || echo "Memory info unavailable"');
        $memInfo = $this->cmd('free -h | grep -E "^Mem:|^Swap:" || echo "Memory info unavailable"');

        if (str_contains($memInfo, 'unavailable')) {
            $this->writeln('⚠️  Memory information unavailable on this system', 'comment');

            return ['status' => 'unavailable'];
        }

        return $this->analyzeMemoryUsage($memInfo);
    }

    /**
     * Analyze memory and swap usage
     */
    protected function analyzeMemoryUsage(string $memInfo): array
    {
        $lines = explode("\n", trim($memInfo));
        $result = ['status' => 'ok'];

        foreach ($lines as $line) {
            $this->writeln($line);

            if (str_starts_with($line, 'Mem:')) {
                $memParts = preg_split('/\s+/', $line);
                $this->writeln("🧠 Memory: {$memParts[2]} used / {$memParts[1]} total ({$memParts[3]} available)");

                $result['memory'] = [
                    'total' => $memParts[1],
                    'used' => $memParts[2],
                    'available' => $memParts[3],
                ];
            }

            if (str_starts_with($line, 'Swap:')) {
                $swapParts = preg_split('/\s+/', $line);
                if ($swapParts[1] !== '0B') {
                    $this->writeln("💾 Swap: {$swapParts[2]} used / {$swapParts[1]} total");

                    $result['swap'] = [
                        'total' => $swapParts[1],
                        'used' => $swapParts[2],
                    ];
                }
            }
        }

        return $result;
    }
}
