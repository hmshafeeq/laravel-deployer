<?php

namespace Shaf\LaravelDeployer\Support;

use Illuminate\Support\Number;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Custom progress bar for deployment operations.
 * Provides visual feedback during file sync and other long-running operations.
 */
class ProgressBar
{
    private int $current = 0;

    private int $startTime;

    private string $prefix = '';

    private bool $started = false;

    private int $lastUpdate = 0;

    public function __construct(
        private OutputInterface $output,
        private int $total,
        private int $barWidth = 30,
        private string $format = 'default'
    ) {
        $this->startTime = time();
    }

    /**
     * Set the prefix for the progress bar line
     */
    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Start the progress bar
     */
    public function start(): void
    {
        $this->started = true;
        $this->current = 0;
        $this->startTime = time();
        $this->display();
    }

    /**
     * Advance the progress bar by n steps
     */
    public function advance(int $step = 1): void
    {
        $this->current = min($this->current + $step, $this->total);

        // Throttle updates to avoid flickering (max 10 updates per second)
        $now = (int) (microtime(true) * 10);
        if ($now !== $this->lastUpdate || $this->current === $this->total) {
            $this->lastUpdate = $now;
            $this->display();
        }
    }

    /**
     * Set current progress value
     */
    public function setProgress(int $value): void
    {
        $this->current = min($value, $this->total);
        $this->display();
    }

    /**
     * Finish the progress bar
     */
    public function finish(): void
    {
        $this->current = $this->total;
        $this->display();
        $this->output->writeln(''); // New line after progress bar
    }

    /**
     * Display the progress bar
     */
    private function display(): void
    {
        if (! $this->started) {
            return;
        }

        $percent = $this->total > 0 ? (int) (($this->current / $this->total) * 100) : 0;
        $filledWidth = $this->total > 0 ? (int) (($this->current / $this->total) * $this->barWidth) : 0;

        $bar = str_repeat('█', $filledWidth);
        $bar .= str_repeat('░', $this->barWidth - $filledWidth);

        $elapsed = time() - $this->startTime;
        $eta = $this->calculateEta($elapsed);

        // Build the line
        $line = match ($this->format) {
            'files' => sprintf(
                '%s[%s] %3d%% (%d/%d files)%s',
                $this->prefix,
                $bar,
                $percent,
                $this->current,
                $this->total,
                $eta ? " ETA: {$eta}" : ''
            ),
            'bytes' => sprintf(
                '%s[%s] %3d%% (%s/%s)%s',
                $this->prefix,
                $bar,
                $percent,
                Number::fileSize($this->current),
                Number::fileSize($this->total),
                $eta ? " ETA: {$eta}" : ''
            ),
            default => sprintf(
                '%s[%s] %3d%% (%d/%d)%s',
                $this->prefix,
                $bar,
                $percent,
                $this->current,
                $this->total,
                $eta ? " ETA: {$eta}" : ''
            ),
        };

        // Clear line and write
        $this->output->write("\r\033[K{$line}");
    }

    /**
     * Calculate estimated time of arrival
     */
    private function calculateEta(int $elapsed): string
    {
        if ($this->current === 0 || $elapsed < 2) {
            return '';
        }

        $rate = $this->current / $elapsed;
        $remaining = $this->total - $this->current;
        $eta = (int) ($remaining / $rate);

        if ($eta < 60) {
            return "{$eta}s";
        }

        $minutes = (int) ($eta / 60);
        $seconds = $eta % 60;

        return "{$minutes}m {$seconds}s";
    }

    /**
     * Create a progress bar for file sync
     */
    public static function forFiles(OutputInterface $output, int $totalFiles, string $prefix = ''): self
    {
        $bar = new self($output, $totalFiles, format: 'files');
        $bar->setPrefix($prefix);

        return $bar;
    }
}
