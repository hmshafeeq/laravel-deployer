<?php

namespace Shaf\LaravelDeployer\Support;

use Symfony\Component\Console\Helper\ProgressBar as SymfonyProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Wrapper around Symfony's ProgressBar for deployment operations.
 */
class ProgressBar
{
    private SymfonyProgressBar $bar;

    public function __construct(
        private OutputInterface $output,
        private int $total,
        private string $format = 'default'
    ) {
        $this->bar = new SymfonyProgressBar($output, $total);
        $this->configureFormat();
    }

    private function configureFormat(): void
    {
        // Use block characters for visual progress
        $this->bar->setBarCharacter('█');
        $this->bar->setEmptyBarCharacter('░');
        $this->bar->setProgressCharacter('█');
        $this->bar->setBarWidth(30);

        $format = match ($this->format) {
            'files' => '%message%[%bar%] %percent:3s%% (%current%/%max% files) %estimated:-6s%',
            'bytes' => '%message%[%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%',
            default => '%message%[%bar%] %percent:3s%% (%current%/%max%) %estimated:-6s%',
        };

        $this->bar->setFormat($format);
        $this->bar->setMessage('');
    }

    public function setPrefix(string $prefix): self
    {
        $this->bar->setMessage($prefix);

        return $this;
    }

    public function start(): void
    {
        $this->bar->start();
    }

    public function advance(int $step = 1): void
    {
        $this->bar->advance($step);
    }

    public function setProgress(int $value): void
    {
        $this->bar->setProgress($value);
    }

    public function finish(): void
    {
        $this->bar->finish();
        $this->output->writeln('');
    }

    public static function forFiles(OutputInterface $output, int $totalFiles, string $prefix = ''): self
    {
        $bar = new self($output, $totalFiles, 'files');
        $bar->setPrefix($prefix);

        return $bar;
    }
}
