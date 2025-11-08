<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Support\Abstract\DeploymentAction;

class SyncCodeAction extends DeploymentAction
{
    public function execute(): void
    {
        $deployPath = $this->getDeployPath();
        $releaseName = $this->deployer->getReleaseName();

        // Check if release symlink exists
        $this->writeln("run if [ -h {$deployPath}/release ]; then echo +precise; fi");
        $result = $this->run("if [ -h {$deployPath}/release ]; then echo +precise; fi");
        if (!empty($result)) {
            $this->writeln($result);
        }

        // Read the symlink
        $this->writeln("run readlink {$deployPath}/release");
        $link = $this->run("readlink {$deployPath}/release");
        $this->writeln($link);

        // Run rsync
        $this->deployer->runRsync();
    }
}
