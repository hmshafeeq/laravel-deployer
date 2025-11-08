<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Deployer\Deployer;

class ReleaseManager
{
    public function __construct(
        protected Deployer $deployer
    ) {}

    /**
     * Create a new release directory and log entry
     */
    public function createRelease(string $releaseName): string
    {
        $deployPath = $this->deployer->getDeployPath();
        $releasePath = "{$deployPath}/releases/{$releaseName}";

        // Write to latest_release
        $this->deployer->writeln("run cd {$deployPath} && (echo {$releaseName} > .dep/latest_release)");
        $this->deployer->run("cd {$deployPath} && (echo {$releaseName} > .dep/latest_release)");

        // Log the release
        $this->logRelease($releaseName);

        // Create release directory
        $this->deployer->writeln("run cd {$deployPath} && (mkdir -p releases/{$releaseName})");
        $this->deployer->run("cd {$deployPath} && (mkdir -p releases/{$releaseName})");

        // Create release symlink
        $this->deployer->writeln("run cd {$deployPath} && (ln -nfs --relative releases/{$releaseName} {$deployPath}/release)");
        $this->deployer->run("cd {$deployPath} && (ln -nfs --relative releases/{$releaseName} {$deployPath}/release)");

        return $releasePath;
    }

    /**
     * Log release information
     */
    protected function logRelease(string $releaseName): void
    {
        $deployPath = $this->deployer->getDeployPath();
        $timestamp = date('Y-m-d\TH:i:s+0000');
        $user = $this->deployer->runLocally('git config --get user.name');
        $branch = $this->deployer->get('branch', 'HEAD');

        $logEntry = json_encode([
            'created_at' => $timestamp,
            'release_name' => $releaseName,
            'user' => $user,
            'target' => $branch
        ]);

        $this->deployer->writeln("run cd {$deployPath} && (echo '{$logEntry}' >> .dep/releases_log)");
        $this->deployer->run("cd {$deployPath} && (echo '{$logEntry}' >> .dep/releases_log)");
    }

    /**
     * Get list of all releases sorted by time (newest first)
     */
    public function getReleases(): array
    {
        $deployPath = $this->deployer->getDeployPath();
        $releasesPath = "{$deployPath}/releases";

        // Check if releases directory exists
        $exists = $this->deployer->test("[ -d {$releasesPath} ]");
        if (!$exists) {
            return [];
        }

        // Get list of releases sorted by time (newest first)
        $output = $this->deployer->run("cd {$releasesPath} && ls -t -1 2>/dev/null || echo ''");

        if (empty(trim($output))) {
            return [];
        }

        $releases = array_filter(explode("\n", trim($output)));

        return array_values($releases);
    }

    /**
     * Get the current release name
     */
    public function getCurrentRelease(): ?string
    {
        $deployPath = $this->deployer->getDeployPath();
        $currentPath = "{$deployPath}/current";

        // Check if current symlink exists
        $exists = $this->deployer->test("[ -L {$currentPath} ]");
        if (!$exists) {
            return null;
        }

        // Get the release name from the symlink
        $output = $this->deployer->run("basename \$(readlink -f {$currentPath}) 2>/dev/null || echo ''");

        if (empty(trim($output))) {
            return null;
        }

        return trim($output);
    }

    /**
     * Display release information
     */
    public function displayReleaseInfo(): void
    {
        $deployPath = $this->deployer->getDeployPath();

        // Check if release symlink exists
        $this->deployer->writeln("run cd {$deployPath} && (if [ -h release ]; then echo +yes; fi)");
        $result = $this->deployer->run("cd {$deployPath} && (if [ -h release ]; then echo +yes; fi)");
        if (!empty($result)) {
            $this->deployer->writeln($result);
        }

        // Check if releases directory has content
        $this->deployer->writeln("run cd {$deployPath} && (if [ -d releases ] && [ \"$(ls -A releases)\" ]; then echo +true; fi)");
        $hasReleases = $this->deployer->run("cd {$deployPath} && (if [ -d releases ] && [ \"$(ls -A releases)\" ]; then echo +true; fi)");
        if (!empty($hasReleases)) {
            $this->deployer->writeln($hasReleases);

            // List existing releases
            $this->deployer->writeln("run cd {$deployPath} && (cd releases && ls -t -1 -d */)");
            $releases = $this->deployer->run("cd {$deployPath} && (cd releases && ls -t -1 -d */)");
            if (!empty($releases)) {
                $lines = explode("\n", trim($releases));
                foreach ($lines as $line) {
                    $this->deployer->writeln($line);
                }
            }
        }

        // Check if releases_log exists
        $this->deployer->writeln("run cd {$deployPath} && (if [ -f .dep/releases_log ]; then echo +true; fi)");
        $hasLog = $this->deployer->run("cd {$deployPath} && (if [ -f .dep/releases_log ]; then echo +true; fi)");
        if (!empty($hasLog)) {
            $this->deployer->writeln($hasLog);

            // Show last releases from log
            $this->deployer->writeln("run cd {$deployPath} && (tail -n 300 .dep/releases_log)");
            $log = $this->deployer->run("cd {$deployPath} && (tail -n 300 .dep/releases_log)");
            if (!empty($log)) {
                $lines = explode("\n", trim($log));
                foreach ($lines as $line) {
                    $this->deployer->writeln($line);
                }
            }
        }
    }

    /**
     * Cleanup old releases, keeping only the specified number
     */
    public function cleanupOldReleases(int $keepCount = 3): void
    {
        $deployPath = $this->deployer->getDeployPath();

        // Remove release symlink
        $this->deployer->writeln("run cd {$deployPath} && if [ -e release ]; then rm release; fi");
        $this->deployer->run("cd {$deployPath} && if [ -e release ]; then rm release; fi");

        // Get list of releases sorted by time, keep only the specified number
        $releases = $this->deployer->run("cd {$deployPath}/releases && ls -t -1 -d */ | tail -n +".($keepCount + 1));
        if (!empty($releases)) {
            $releasesToDelete = explode("\n", trim($releases));
            foreach ($releasesToDelete as $release) {
                $release = trim($release, '/');
                $this->deployer->writeln("run  rm -rf {$deployPath}/releases/{$release}");
                $this->deployer->run("rm -rf {$deployPath}/releases/{$release}");
            }
        }
    }

    /**
     * Get rollback information
     */
    public function getRollbackInfo(): array
    {
        $releases = $this->getReleases();
        $currentRelease = $this->getCurrentRelease();

        $info = [
            'current' => $currentRelease,
            'releases' => $releases,
            'can_rollback' => false,
            'previous' => null,
        ];

        if ($currentRelease && count($releases) > 1) {
            $currentIndex = array_search($currentRelease, $releases);
            if ($currentIndex !== false && $currentIndex < count($releases) - 1) {
                $info['can_rollback'] = true;
                $info['previous'] = $releases[$currentIndex + 1];
            }
        }

        return $info;
    }
}
