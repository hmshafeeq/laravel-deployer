<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Constants\Timeouts;
use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\ServerConnection;
use Shaf\LaravelDeployer\Exceptions\SSHConnectionException;
use Spatie\Ssh\Ssh;

class RemoteCommandExecutor implements CommandExecutor
{
    private Ssh $ssh;

    public function __construct(
        private ServerConnection $connection,
        private OutputService $output,
        private string $workingDirectory = '',
        private int $timeout = Timeouts::DEFAULT_COMMAND,
    ) {
        $this->initializeSsh();
    }

    public function execute(string $command): string
    {
        $this->output->command($command);

        try {
            $process = $this->ssh->execute($command);
            $output = trim($process->getOutput());

            if (!$process->isSuccessful()) {
                throw SSHConnectionException::commandFailed(
                    $command,
                    $process->getErrorOutput()
                );
            }

            $this->output->commandOutput($output);

            return $output;
        } catch (\Exception $e) {
            throw SSHConnectionException::commandFailed($command, $e->getMessage());
        }
    }

    public function test(string $condition): bool
    {
        try {
            $process = $this->ssh->execute($condition . ' && echo "true" || echo "false"');
            return trim($process->getOutput()) === 'true';
        } catch (\Exception $e) {
            return false;
        }
    }

    public function isLocal(): bool
    {
        return false;
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
        $this->ssh->setTimeout($timeout);
    }

    private function initializeSsh(): void
    {
        $this->ssh = Ssh::create($this->connection->user, $this->connection->host);

        if ($this->connection->disableStrictHostKeyChecking) {
            $this->ssh->disableStrictHostKeyChecking();
        }

        if ($this->connection->disablePasswordAuth) {
            $this->ssh->disablePasswordAuthentication();
        }

        if ($this->connection->port) {
            $this->ssh->usePort($this->connection->port);
        }

        $this->ssh->setTimeout($this->timeout);
    }
}
