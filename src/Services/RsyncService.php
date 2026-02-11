<?php

namespace Shaf\LaravelDeployer\Services;

use Illuminate\Support\Number;
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

    private int $totalBytesTransferred = 0;

    private int $filesSynced = 0;

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

    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;

        return $this;
    }

    public function sync(string $destination, ?string $filesFromPath = null): void
    {
        // Reset counters
        $this->totalBytesTransferred = 0;
        $this->filesSynced = 0;

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

        // Build command - include -v flag if we need progress output, --stats for size info
        $command = $this->buildRsyncCommand($source, $destinationPath, $showProgress, $filesFromPath);

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
        $fullOutput = '';

        $progressCount = 0;
        $process->run(function ($type, $buffer) use (&$syncedFiles, &$fileCount, &$fullOutput, &$progressCount, $progressBar, $showProgress, $totalFiles) {
            $fullOutput .= $buffer;

            if ($type === Process::ERR) {
                $this->cmdService?->error($buffer);
            } else {
                $lines = explode("\n", $buffer);
                foreach ($lines as $line) {
                    $trimmedLine = trim($line);
                    if (! empty($trimmedLine) && ! $this->isDirectoryLine($trimmedLine) && $this->isActualFileTransfer($trimmedLine)) {
                        $syncedFiles[] = $trimmedLine;
                        $fileCount++;
                        // Cap progress bar at expected total to avoid inflated counts
                        if ($showProgress && $progressBar && $progressCount < $totalFiles) {
                            $progressBar->advance();
                            $progressCount++;
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

        // Parse rsync stats for transferred bytes
        $this->parseRsyncStats($fullOutput);
        $this->filesSynced = $fileCount;

        // Show synced files in verbose mode (only if not already showing progress)
        if ($this->verbose && ! $showProgress && ! empty($syncedFiles)) {
            $this->cmdService?->info('Synced files:');
            foreach ($syncedFiles as $file) {
                $this->cmdService?->line("  → {$file}");
            }
        }

        // Show transfer summary
        $sizeFormatted = Number::fileSize($this->totalBytesTransferred);
        $this->cmdService?->success("Files synced successfully ({$fileCount} files, {$sizeFormatted})");
    }

    /**
     * Parse rsync stats output to extract transferred bytes
     */
    private function parseRsyncStats(string $output): void
    {
        // Match "sent X bytes" from rsync output
        // Example: "sent 12,345 bytes  received 567 bytes  4,303.73 bytes/sec"
        if (preg_match('/sent\s+([\d,]+)\s+bytes/i', $output, $matches)) {
            $this->totalBytesTransferred = (int) str_replace(',', '', $matches[1]);
        }
    }

    /**
     * Get the total bytes transferred in the last sync
     */
    public function getTotalBytesTransferred(): int
    {
        return $this->totalBytesTransferred;
    }

    /**
     * Get the number of files synced in the last sync
     */
    public function getFilesSynced(): int
    {
        return $this->filesSynced;
    }

    /**
     * Get formatted transfer size
     */
    public function getFormattedTransferSize(): string
    {
        return Number::fileSize($this->totalBytesTransferred);
    }

    public function setExcludes(array $excludes): self
    {
        $this->excludes = $excludes;

        return $this;
    }

    public function setIncludes(array $includes): self
    {
        $this->includes = $includes;

        return $this;
    }

    public function addExclude(string $pattern): self
    {
        $this->excludes[] = $pattern;

        return $this;
    }

    public function addInclude(string $pattern): self
    {
        $this->includes[] = $pattern;

        return $this;
    }

    private function buildRsyncCommand(string $source, string $destination, bool $forProgress = false, ?string $filesFromPath = null): string
    {
        $parts = ['rsync'];

        // Add flags
        // Always include -v when we need file output for progress bar or verbose mode
        $flags = Commands::RSYNC_FLAGS;
        if ($this->verbose || $forProgress) {
            $flags .= 'v';
        }
        $parts[] = '-'.$flags;

        // Add --stats to get transfer size information
        $parts[] = '--stats';

        // Add SSH options only for remote deployments
        if (! $this->config->isLocal) {
            $sshOptions = Commands::RSYNC_SSH_OPTIONS;
            if ($this->config->identityFile) {
                $identityFile = $this->config->identityFile;
                if (str_starts_with($identityFile, '~')) {
                    $home = $_SERVER['HOME'] ?? getenv('HOME') ?? '/tmp';
                    $identityFile = str_replace('~', $home, $identityFile);
                }
                $sshOptions .= " -i {$identityFile}";
            }
            $parts[] = "-e '{$sshOptions}'";
        }

        // When using --files-from, skip --delete options (only push changed files)
        if ($filesFromPath !== null) {
            $parts[] = "--files-from='{$filesFromPath}'";
        } else {
            // Add rsync options (includes --delete, --delete-after)
            foreach (Commands::RSYNC_OPTIONS as $option) {
                $parts[] = "--{$option}";
            }
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

        // Skip rsync statistics lines (from --stats flag)
        $statsPatterns = [
            '/^sent \d+/',
            '/^total size/',
            '/bytes\/sec$/',
            '/^sending incremental/',
            '/^building file list/',
            '/^Number of files/',
            '/^Number of created/',
            '/^Number of deleted/',
            '/^Number of regular/',
            '/^Total file size/',
            '/^Total transferred/',
            '/^Literal data/',
            '/^Matched data/',
            '/^File list size/',
            '/^File list generation/',
            '/^File list transfer/',
            '/^Total bytes sent/',
            '/^Total bytes received/',
            '/^\s*$/', // Empty lines
        ];

        foreach ($statsPatterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return false;
            }
        }

        return true;
    }
}
