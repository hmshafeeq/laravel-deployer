<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Constants\Commands;
use Shaf\LaravelDeployer\Constants\Timeouts;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\SyncDiff;
use Shaf\LaravelDeployer\Exceptions\RsyncException;
use Shaf\LaravelDeployer\Support\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class RsyncService
{
    private array $excludes = [];

    private array $includes = [];

    private bool $verbose = false;

    private ?SyncDiff $syncDiff = null;

    private ?OutputInterface $output = null;

    public function __construct(
        private DeploymentConfig $config,
        private string $sourcePath,
        private ?CommandService $cmdService = null,
    ) {
        $this->excludes = $config->rsyncExcludes;
        $this->includes = $config->rsyncIncludes;
        $this->verbose = $cmdService?->isVerbose() ?? false;
    }

    /**
     * Set the sync diff for progress tracking
     */
    public function setSyncDiff(?SyncDiff $diff): self
    {
        $this->syncDiff = $diff;

        return $this;
    }

    /**
     * Set the output interface for progress bar
     */
    public function setOutput(?OutputInterface $output): self
    {
        $this->output = $output;

        return $this;
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

        // Setup progress bar if we have sync diff info and output interface
        $totalFiles = $this->syncDiff?->totalCount() ?? 0;
        $showProgress = $this->config->showUploadProgress && $totalFiles > 0 && $this->output !== null;

        // Build command - include -v flag if we need progress output
        $command = $this->buildRsyncCommand($source, $destinationPath, $showProgress);

        $this->cmdService?->debug("Rsync command: {$command}");

        $process = Process::fromShellCommandline($command, $this->sourcePath);
        $process->setTimeout(Timeouts::RSYNC);

        $progressBar = null;
        if ($showProgress) {
            $prefix = "[{$this->config->environment->value}] ";
            $progressBar = ProgressBar::forFiles($this->output, $totalFiles, $prefix);
            $progressBar->start();
        }

        $syncedFiles = [];
        $fileCount = 0;
        $process->run(function ($type, $buffer) use (&$syncedFiles, &$fileCount, $progressBar, $showProgress) {
            if ($type === Process::ERR) {
                $this->cmdService?->error($buffer);
            } else {
                $lines = explode("\n", $buffer);
                foreach ($lines as $line) {
                    $trimmedLine = trim($line);
                    if (! empty($trimmedLine) && ! $this->isDirectoryLine($trimmedLine) && $this->isActualFileTransfer($trimmedLine)) {
                        $syncedFiles[] = $trimmedLine;
                        $fileCount++;
                        if ($showProgress && $progressBar) {
                            $progressBar->advance();
                        }
                    }
                }
            }
        });

        if ($showProgress && $progressBar) {
            $progressBar->finish();
        }

        if (! $process->isSuccessful()) {
            throw RsyncException::failed($process->getErrorOutput());
        }

        // Show synced files in verbose mode (only if not already showing progress)
        if ($this->verbose && ! $showProgress && ! empty($syncedFiles)) {
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

    private function buildRsyncCommand(string $source, string $destination, bool $forProgress = false): string
    {
        $parts = ['rsync'];

        // Add flags
        // Always include -v when we need file output for progress bar or verbose mode
        $flags = Commands::RSYNC_FLAGS;
        if ($this->verbose || $forProgress) {
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
