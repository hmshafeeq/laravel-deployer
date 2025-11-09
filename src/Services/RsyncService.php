<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Constants\Commands;
use Shaf\LaravelDeployer\Constants\Timeouts;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Exceptions\RsyncException;
use Symfony\Component\Process\Process;

class RsyncService
{
    private array $excludes = [];
    private array $includes = [];

    public function __construct(
        private OutputService $output,
        private DeploymentConfig $config,
        private string $sourcePath,
    ) {
        $this->excludes = $config->rsyncExcludes;
        $this->includes = $config->rsyncIncludes;
    }

    public function sync(string $destination): void
    {
        $this->output->info("Syncing files to release...");

        $source = rtrim($this->sourcePath, '/') . '/';
        $destinationPath = "{$this->config->remoteUser}@{$this->config->hostname}:{$destination}/";

        $command = $this->buildRsyncCommand($source, $destinationPath);

        $this->output->debug("Rsync command: {$command}");

        $process = Process::fromShellCommandline($command, $this->sourcePath);
        $process->setTimeout(Timeouts::RSYNC);

        $process->run(function ($type, $buffer) {
            if (Process::ERR === $type) {
                $this->output->error($buffer);
            } else {
                $lines = explode("\n", $buffer);
                foreach ($lines as $line) {
                    if (!empty(trim($line)) && !$this->isDirectoryLine($line)) {
                        $this->output->commandOutput($line);
                    }
                }
            }
        });

        if (!$process->isSuccessful()) {
            throw RsyncException::failed($process->getErrorOutput());
        }

        $this->output->success("Files synced successfully");
    }

    public function setExcludes(array $excludes): void
    {
        $this->excludes = $excludes;
    }

    public function setIncludes(array $includes): void
    {
        $this->includes = $includes;
    }

    public function addExclude(string $pattern): void
    {
        $this->excludes[] = $pattern;
    }

    public function addInclude(string $pattern): void
    {
        $this->includes[] = $pattern;
    }

    private function buildRsyncCommand(string $source, string $destination): string
    {
        $parts = ['rsync'];

        // Add flags
        $parts[] = '-' . Commands::RSYNC_FLAGS;

        // Add SSH options
        $parts[] = "-e '" . Commands::RSYNC_SSH_OPTIONS . "'";

        // Add rsync options
        foreach (Commands::RSYNC_OPTIONS as $option) {
            $parts[] = "--{$option}";
        }

        // Add includes first (they need to come before excludes)
        foreach ($this->includes as $include) {
            $parts[] = "--include='{$include}'";
        }

        // Add excludes
        foreach ($this->excludes as $exclude) {
            $parts[] = "--exclude='{$exclude}'";
        }

        // Add source and destination
        $parts[] = "'{$source}'";
        $parts[] = "'{$destination}'";

        return implode(' ', $parts);
    }

    private function isDirectoryLine(string $line): bool
    {
        // Skip directory-only rsync output lines
        return str_ends_with($line, '/') || empty(trim($line));
    }
}
