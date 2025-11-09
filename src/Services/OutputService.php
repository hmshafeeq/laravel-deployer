<?php

namespace Shaf\LaravelDeployer\Services;

use Illuminate\Console\OutputStyle;
use Shaf\LaravelDeployer\Enums\VerbosityLevel;
use Symfony\Component\Console\Output\OutputInterface;

class OutputService
{
    private VerbosityLevel $verbosityLevel;

    public function __construct(
        private OutputInterface $output,
        private string $prefix = '',
    ) {
        $this->verbosityLevel = $this->determineVerbosityLevel();
    }

    public function info(string $message): void
    {
        if ($this->shouldDisplay(VerbosityLevel::NORMAL)) {
            $this->writeln("<info>{$this->prefix}{$message}</info>");
        }
    }

    public function error(string $message): void
    {
        $this->writeln("<error>{$this->prefix}{$message}</error>");
    }

    public function comment(string $message): void
    {
        if ($this->shouldDisplay(VerbosityLevel::NORMAL)) {
            $this->writeln("<comment>{$this->prefix}{$message}</comment>");
        }
    }

    public function debug(string $message): void
    {
        if ($this->shouldDisplay(VerbosityLevel::DEBUG)) {
            $this->writeln("<comment>{$this->prefix}[DEBUG] {$message}</comment>");
        }
    }

    public function task(string $name): void
    {
        if ($this->shouldDisplay(VerbosityLevel::VERBOSE)) {
            $this->writeln("\n<fg=cyan>task {$name}</>");
        }
    }

    public function command(string $command): void
    {
        if ($this->shouldDisplay(VerbosityLevel::VERBOSE)) {
            $this->writeln("<comment>{$this->prefix}run {$command}</comment>");
        }
    }

    public function commandOutput(string $output): void
    {
        if ($this->shouldDisplay(VerbosityLevel::VERY_VERBOSE) && !empty(trim($output))) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                if (!empty(trim($line))) {
                    $this->writeln("{$this->prefix}{$line}");
                }
            }
        }
    }

    public function success(string $message): void
    {
        if ($this->shouldDisplay(VerbosityLevel::NORMAL)) {
            $this->writeln("<info>✓ {$message}</info>");
        }
    }

    public function warning(string $message): void
    {
        if ($this->shouldDisplay(VerbosityLevel::NORMAL)) {
            $this->writeln("<comment>⚠ {$message}</comment>");
        }
    }

    public function line(string $message): void
    {
        if ($this->shouldDisplay(VerbosityLevel::NORMAL)) {
            $this->writeln($this->prefix . $message);
        }
    }

    public function newLine(int $count = 1): void
    {
        if ($this->shouldDisplay(VerbosityLevel::NORMAL)) {
            for ($i = 0; $i < $count; $i++) {
                $this->output->writeln('');
            }
        }
    }

    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    public function getVerbosityLevel(): VerbosityLevel
    {
        return $this->verbosityLevel;
    }

    public function isQuiet(): bool
    {
        return $this->verbosityLevel === VerbosityLevel::QUIET;
    }

    public function isVerbose(): bool
    {
        return in_array($this->verbosityLevel, [
            VerbosityLevel::VERBOSE,
            VerbosityLevel::VERY_VERBOSE,
            VerbosityLevel::DEBUG,
        ]);
    }

    public function isVeryVerbose(): bool
    {
        return in_array($this->verbosityLevel, [
            VerbosityLevel::VERY_VERBOSE,
            VerbosityLevel::DEBUG,
        ]);
    }

    public function isDebug(): bool
    {
        return $this->verbosityLevel === VerbosityLevel::DEBUG;
    }

    private function shouldDisplay(VerbosityLevel $messageLevel): bool
    {
        return $this->verbosityLevel->shouldShow($messageLevel);
    }

    private function determineVerbosityLevel(): VerbosityLevel
    {
        $verbosity = $this->output->getVerbosity();

        return match ($verbosity) {
            OutputInterface::VERBOSITY_QUIET => VerbosityLevel::QUIET,
            OutputInterface::VERBOSITY_NORMAL => VerbosityLevel::NORMAL,
            OutputInterface::VERBOSITY_VERBOSE => VerbosityLevel::VERBOSE,
            OutputInterface::VERBOSITY_VERY_VERBOSE => VerbosityLevel::VERY_VERBOSE,
            OutputInterface::VERBOSITY_DEBUG => VerbosityLevel::DEBUG,
            default => VerbosityLevel::NORMAL,
        };
    }

    private function writeln(string $message): void
    {
        $this->output->writeln($message);
    }
}
