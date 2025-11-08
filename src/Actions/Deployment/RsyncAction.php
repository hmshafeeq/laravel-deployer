<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class RsyncAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $deployPath = $this->deployer->getDeployPath();
        $releaseName = $this->deployer->getReleaseName();

        // Check if release symlink exists
        $this->deployer->writeln("run if [ -h {$deployPath}/release ]; then echo +precise; fi");
        $result = $this->deployer->run("if [ -h {$deployPath}/release ]; then echo +precise; fi");
        if (!empty($result)) {
            $this->deployer->writeln($result);
        }

        // Read the symlink
        $this->deployer->writeln("run readlink {$deployPath}/release");
        $link = $this->deployer->run("readlink {$deployPath}/release");
        $this->deployer->writeln($link);

        // Run rsync
        $this->deployer->runRsync();
    }

    public function getName(): string
    {
        return 'rsync';
    }
}
