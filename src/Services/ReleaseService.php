<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Deployer;

class ReleaseService
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
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
