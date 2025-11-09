<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Constants\Timeouts;
use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Exceptions\TaskExecutionException;
use Symfony\Component\Process\Process;

class LocalCommandExecutor implements CommandExecutor
{
    public function __construct(
        private OutputService $output,
        private string $workingDirectory,
        private int $timeout = Timeouts::DEFAULT_COMMAND,
    ) {}

    public function execute(string $command): string
    {
        $this->output->command($command);

        $process = Process::fromShellCommandline($command, $this->workingDirectory);
        $process->setTimeout($this->timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw TaskExecutionException::commandFailed($command, $process->getErrorOutput());
        }

        $output = trim($process->getOutput());
        $this->output->commandOutput($output);

        return $output;
    }

    public function test(string $condition): bool
    {
        $process = Process::fromShellCommandline($condition, $this->workingDirectory);
        $process->run();

        return $process->isSuccessful();
    }

    public function isLocal(): bool
    {
        return true;
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }
}
