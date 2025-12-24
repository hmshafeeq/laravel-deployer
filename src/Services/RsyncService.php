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

    private bool $verbose = false;

    public function __construct(
        private DeploymentConfig $config,
        private string $sourcePath,
        private ?CommandService $cmdService = null,
    ) {
        $this->excludes = $config->rsyncExcludes;
        $this->includes = $config->rsyncIncludes;
        $this->verbose = $cmdService?->isVerbose() ?? false;
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    public function sync(string $destination): void
    {
        $this->cmdService?->info('Syncing files to release...');

        $source = rtrim($this->sourcePath, '/').'/';

        // For local deployments, use direct path; for remote, use SSH
        if ($this->config->isLocal) {
            $destinationPath = rtrim($destination, '/').'/';
        } else {
            $destinationPath = "{$this->config->remoteUser}@{$this->config->hostname}:{$destination}/";
        }

        $command = $this->buildRsyncCommand($source, $destinationPath);

        $this->cmdService?->debug("Rsync command: {$command}");

        $process = Process::fromShellCommandline($command, $this->sourcePath);
        $process->setTimeout(Timeouts::RSYNC);

        $syncedFiles = [];
        $process->run(function ($type, $buffer) use (&$syncedFiles) {
            if ($type === Process::ERR) {
                $this->cmdService?->error($buffer);
            } else {
                $lines = explode("\n", $buffer);
                foreach ($lines as $line) {
                    $trimmedLine = trim($line);
                    if (! empty($trimmedLine) && ! $this->isDirectoryLine($trimmedLine) && $this->isActualFileTransfer($trimmedLine)) {
                        $syncedFiles[] = $trimmedLine;
                    }
                }
            }
        });

        if (! $process->isSuccessful()) {
            throw RsyncException::failed($process->getErrorOutput());
        }

        // Show synced files in verbose mode
        if ($this->verbose && ! empty($syncedFiles)) {
            $this->cmdService?->info('Synced files:');
            foreach ($syncedFiles as $file) {
                $this->cmdService?->line("  → {$file}");
            }
        }

        $this->cmdService?->success('Files synced successfully');
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

        // Add flags (include -v for verbose mode to show file list)
        $flags = Commands::RSYNC_FLAGS;
        if ($this->verbose) {
            $flags .= 'v';
        }
        $parts[] = '-'.$flags;

        // Add SSH options only for remote deployments
        if (! $this->config->isLocal) {
            $parts[] = "-e '".Commands::RSYNC_SSH_OPTIONS."'";
        }

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

    private function isActualFileTransfer(string $line): bool
    {
        // Skip lines that are just dots (unchanged files in rsync verbose output)
        if (preg_match('/^\.+$/', $line)) {
            return false;
        }

        // Skip rsync statistics lines
        $statsPatterns = [
            '/^sent \d+/',
            '/^total size/',
            '/bytes\/sec$/',
            '/^sending incremental/',
            '/^building file list/',
        ];

        foreach ($statsPatterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return false;
            }
        }

        return true;
    }
}
