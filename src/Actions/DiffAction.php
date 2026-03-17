<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\SyncDiff;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\SshService;
use Symfony\Component\Process\Process;

/**
 * Diff action for showing sync differences before deployment.
 * Displays files that will be added, modified, or deleted.
 */
class DiffAction
{
    public function __construct(
        private CommandService $cmd,
        private DeploymentConfig $config,
        private string $sourcePath
    ) {}

    /**
     * Show sync differences and return diff data
     */
    public function show(): SyncDiff
    {
        $this->cmd->section('SYNC DIFFERENCE - FILES TO DEPLOY');

        $diff = $this->calculateDiff();

        if ($diff->isEmpty()) {
            $this->cmd->info('  ✨ No changes detected - everything is already in sync!');
            $this->cmd->newLine();

            return $diff;
        }

        $this->displayStatistics($diff);
        $this->displayChanges($diff);

        return $diff;
    }

    /**
     * Calculate sync differences without displaying output
     */
    public function calculate(): SyncDiff
    {
        return $this->calculateDiff();
    }

    /**
     * Ask for confirmation to proceed with changes
     */
    public function confirmChanges(SyncDiff $diff): bool
    {
        if ($diff->isEmpty()) {
            return true;
        }

        $this->cmd->newLine();

        // Extra warning for production deletions
        if ($this->config->environment->isProduction() && $diff->hasDeleted()) {
            $this->cmd->warning("⚠️  WARNING: Deployment will DELETE {$diff->deletedCount()} file(s) on PRODUCTION!");
            $this->cmd->newLine();
        }

        return $this->cmd->confirm('Do you want to proceed with uploading these changes?', true);
    }

    /**
     * Display upload progress header
     */
    public function showUploadProgress(SyncDiff $diff): void
    {
        $this->cmd->section('UPLOADING FILES TO SERVER');

        if (! $diff->isEmpty()) {
            $this->cmd->info("  Uploading {$diff->totalCount()} file(s)...");
            $this->cmd->newLine();
        }
    }

    /**
     * Display upload completion
     */
    public function showUploadComplete(): void
    {
        $this->cmd->newLine();
        $this->cmd->success('  Files uploaded successfully!');
        $this->cmd->newLine();
    }

    /**
     * Calculate sync differences using rsync dry-run (local temp comparison)
     */
    private function calculateDiff(): SyncDiff
    {
        $this->cmd->debug('Calculating sync differences...');

        $source = rtrim($this->sourcePath, '/').'/';
        $tempDir = sys_get_temp_dir().'/deployer-diff-'.uniqid();
        mkdir($tempDir, 0700, true);

        try {
            $command = $this->buildDryRunCommand($source, $tempDir);
            $command = SshService::wrapForWsl($command, [$source, $tempDir]);
            $this->cmd->debug("Dry-run command: {$command}");

            $process = Process::fromShellCommandline($command, $this->sourcePath);
            $process->setTimeout(300);
            $process->run();

            $output = $process->getOutput();
            $this->cmd->debug('Parsing rsync output...');

            return $this->parseDryRunOutput($output);
        } finally {
            @rmdir($tempDir);
        }
    }

    /**
     * Calculate sync differences against a remote destination
     */
    public function calculateRemoteDiff(string $remotePath): SyncDiff
    {
        $this->cmd->debug('Calculating remote sync differences...');

        $source = rtrim($this->sourcePath, '/').'/';

        // Build destination path for remote
        if ($this->config->isLocal) {
            $destination = rtrim($remotePath, '/').'/';
        } else {
            $destination = "{$this->config->remoteUser}@{$this->config->hostname}:{$remotePath}/";
        }

        $command = $this->buildRemoteDryRunCommand($source, $destination);
        $command = SshService::wrapForWsl($command, [$source]);
        $this->cmd->debug("Remote dry-run command: {$command}");

        $process = Process::fromShellCommandline($command, $this->sourcePath);
        $process->setTimeout(300);
        $process->run();

        $output = $process->getOutput();
        $stderr = $process->getErrorOutput();

        $this->cmd->debug("Rsync exit code: {$process->getExitCode()}");
        $this->cmd->debug('Rsync stdout length: '.strlen($output));
        if ($stderr) {
            $this->cmd->debug("Rsync stderr: {$stderr}");
        }
        if ($output) {
            $this->cmd->debug('Rsync output (first 500 chars): '.substr($output, 0, 500));
        }

        // If rsync failed with non-zero exit (and not just grep returning 1 for no matches),
        // log a warning - the || echo '' at the end means exit code is usually 0
        if ($process->getExitCode() !== 0 && empty($output) && ! empty($stderr)) {
            $this->cmd->warning("Remote diff failed: {$stderr}");
        }

        return $this->parseDryRunOutput($output);
    }

    /**
     * Show sync differences against a remote destination
     */
    public function showRemoteDiff(string $remotePath): SyncDiff
    {
        $this->cmd->section('SYNC DIFFERENCE - FILES TO DEPLOY');

        $diff = $this->calculateRemoteDiff($remotePath);

        if ($diff->isEmpty()) {
            $this->cmd->info('  ✨ No changes detected - everything is already in sync!');
            $this->cmd->newLine();

            return $diff;
        }

        $this->displayStatistics($diff);
        $this->displayChanges($diff);

        return $diff;
    }

    /**
     * Build rsync dry-run command (local comparison)
     */
    private function buildDryRunCommand(string $source, string $destination): string
    {
        // Use -c (checksum) to match actual sync behavior - compare by content, not timestamp
        $parts = ['rsync', '-avnc'];

        foreach ($this->config->rsyncIncludes as $include) {
            $parts[] = "--include='{$include}'";
        }

        foreach ($this->config->rsyncExcludes as $exclude) {
            $parts[] = "--exclude='{$exclude}'";
        }

        $parts[] = '--delete';
        $parts[] = "'{$source}/'";
        $parts[] = "'{$destination}/'";

        // Filter rsync itemize-changes output:
        // <f = file being sent (upload), cd = new directory, deleting = file removal
        return implode(' ', $parts)." 2>&1 | grep -E '^(deleting |<f|cd)' || echo ''";
    }

    /**
     * Build rsync dry-run command for remote comparison
     */
    private function buildRemoteDryRunCommand(string $source, string $destination): string
    {
        // Use -c (checksum) to match actual sync behavior - compare by content, not timestamp
        $parts = ['rsync', '-avnc', '--itemize-changes'];

        // Add SSH options for remote
        if (! $this->config->isLocal) {
            $sshService = SshService::fromConfig($this->config);
            $sshOptions = $sshService->buildRsyncSshOptions();
            // Add batch mode and connect timeout for non-interactive diff
            $sshOptions .= ' -o BatchMode=yes -o ConnectTimeout=10';
            $parts[] = "-e '{$sshOptions}'";
        }

        foreach ($this->config->rsyncIncludes as $include) {
            $parts[] = "--include='{$include}'";
        }

        foreach ($this->config->rsyncExcludes as $exclude) {
            $parts[] = "--exclude='{$exclude}'";
        }

        $parts[] = '--delete';
        $parts[] = "'{$source}'";
        $parts[] = "'{$destination}'";

        // Filter rsync itemize-changes output:
        // <f = file being sent (upload), cd = new directory, deleting = file removal
        return implode(' ', $parts)." 2>&1 | grep -E '^(deleting |<f|cd)' || echo ''";
    }

    /**
     * Parse rsync dry-run output
     *
     * Rsync itemize-changes format:
     * - <f++++++++ = new file being uploaded
     * - <f.st..... = modified file being uploaded (size/time change)
     * - cd++++++++ = new directory being created
     * - deleting X = file/directory being deleted
     */
    private function parseDryRunOutput(string $output): SyncDiff
    {
        $newFiles = [];
        $modifiedFiles = [];
        $deletedFiles = [];

        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (str_starts_with($line, 'deleting ')) {
                $file = substr($line, 9);
                if (! str_ends_with($file, '/')) {
                    $deletedFiles[] = $file;
                }
            } elseif (preg_match('/^<f\+{7,}\s+(.+)$/', $line, $matches)) {
                // New file: <f++++++++ filename (7+ plus signs)
                $newFiles[] = $matches[1];
            } elseif (preg_match('/^<f[^+]/', $line)) {
                // Modified file: <f.st..... or similar (not all plus signs)
                $parts = preg_split('/\s+/', $line, 2);
                if (isset($parts[1])) {
                    $modifiedFiles[] = $parts[1];
                }
            }
        }

        return new SyncDiff($newFiles, $modifiedFiles, $deletedFiles);
    }

    /**
     * Display statistics
     */
    private function displayStatistics(SyncDiff $diff): void
    {
        $this->cmd->info("  Total changes: <fg=yellow>{$diff->totalCount()}</> file(s)");
        $this->cmd->newLine();
    }

    /**
     * Display changes by category
     */
    private function displayChanges(SyncDiff $diff): void
    {
        $displayLimit = $this->config->diffDisplayLimit;

        if ($diff->hasNew()) {
            $this->displayFileList('New files', $diff->newFiles, 'green', '+', $displayLimit);
        }

        if ($diff->hasModified()) {
            $this->displayFileList('Modified files', $diff->modifiedFiles, 'yellow', '~', $displayLimit);
        }

        if ($diff->hasDeleted()) {
            $this->displayFileList('Deleted files', $diff->deletedFiles, 'red', '-', $displayLimit);
        }
    }

    /**
     * Display a list of files
     */
    private function displayFileList(string $title, array $files, string $color, string $symbol, int $limit): void
    {
        $count = count($files);
        $this->cmd->write("  <fg={$color}>● {$title} ({$count}):</>");
        $this->cmd->newLine();

        $filesToShow = array_slice($files, 0, $limit);
        foreach ($filesToShow as $file) {
            $this->cmd->write("    <fg={$color}>{$symbol}</> {$file}");
            $this->cmd->newLine();
        }

        if ($count > $limit) {
            $remaining = $count - $limit;
            $this->cmd->write("    <fg=gray>... and {$remaining} more file(s)</>");
            $this->cmd->newLine();
        }

        $this->cmd->newLine();
    }
}
