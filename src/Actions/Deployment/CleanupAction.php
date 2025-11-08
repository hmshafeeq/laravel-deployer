<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class CleanupAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $deployPath = $this->deployer->getDeployPath();
        $keepReleases = $this->deployer->get('keep_releases', 3);

        // Remove release symlink
        $this->deployer->writeln("run cd {$deployPath} && if [ -e release ]; then rm release; fi");
        $this->deployer->run("cd {$deployPath} && if [ -e release ]; then rm release; fi");

        // Get list of releases sorted by time, keep only the specified number
        $releases = $this->deployer->run("cd {$deployPath}/releases && ls -t -1 -d */ | tail -n +".($keepReleases + 1));
        if (!empty($releases)) {
            $releasesToDelete = explode("\n", trim($releases));
            foreach ($releasesToDelete as $release) {
                $release = trim($release, '/');
                $this->deployer->writeln("run  rm -rf {$deployPath}/releases/{$release}");
                $this->deployer->run("rm -rf {$deployPath}/releases/{$release}");
            }
        }
    }

    public function getName(): string
    {
        return 'deploy:cleanup';
    }
}
