<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Constants\Paths;
use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\ReleaseInfo;
use Symfony\Component\Process\Process;

class ReleaseManager
{
    public function __construct(
        private CommandExecutor $executor,
        private OutputService $output,
        private string $deployPath,
    ) {}

    public function generateReleaseName(): string
    {
        $yearMonth = date('Ym');
        $counterDir = "{$this->deployPath}/" . Paths::COUNTER_DIR;
        $counterFile = "{$counterDir}/{$yearMonth}.txt";

        // Ensure the folder exists
        $this->executor->execute("mkdir -p {$counterDir}");

        // Read counter or start from 0
        $count = $this->executor->execute("if [ -f {$counterFile} ]; then cat {$counterFile}; else echo 0; fi");
        $count = (int) $count + 1;

        // Save updated counter
        $this->executor->execute("echo {$count} > {$counterFile}");

        $releaseName = "{$yearMonth}.{$count}";

        $this->output->debug("Generated release name: {$releaseName}");

        return $releaseName;
    }

    public function getReleases(): array
    {
        $releasesPath = "{$this->deployPath}/" . Paths::RELEASES_DIR;

        // Check if releases directory exists
        $exists = $this->executor->test("[ -d {$releasesPath} ]");
        if (!$exists) {
            return [];
        }

        // Get list of releases sorted by time (newest first)
        $output = $this->executor->execute("cd {$releasesPath} && ls -t -1 2>/dev/null || echo ''");

        if (empty(trim($output))) {
            return [];
        }

        $releases = array_filter(explode("\n", trim($output)));

        return array_values($releases);
    }

    public function getCurrentRelease(): ?string
    {
        $currentPath = "{$this->deployPath}/" . Paths::CURRENT_SYMLINK;

        // Check if current symlink exists
        $exists = $this->executor->test("[ -L {$currentPath} ]");
        if (!$exists) {
            return null;
        }

        // Get the release name from the symlink
        $output = $this->executor->execute("basename \$(readlink -f {$currentPath}) 2>/dev/null || echo ''");

        if (empty(trim($output))) {
            return null;
        }

        return trim($output);
    }

    public function logRelease(ReleaseInfo $release): void
    {
        $logFile = "{$this->deployPath}/" . Paths::RELEASES_LOG;
        $logEntry = json_encode($release->toLogEntry());

        $this->executor->execute("echo '{$logEntry}' >> {$logFile}");
    }

    public function writeLatestRelease(string $releaseName): void
    {
        $latestReleaseFile = "{$this->deployPath}/" . Paths::LATEST_RELEASE;
        $this->executor->execute("echo {$releaseName} > {$latestReleaseFile}");
    }

    public function getUser(): string
    {
        if ($this->executor->isLocal()) {
            $process = Process::fromShellCommandline('git config --get user.name');
            $process->run();
            return trim($process->getOutput()) ?: 'unknown';
        }

        return 'deployer';
    }
}
