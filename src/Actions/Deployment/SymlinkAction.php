<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class SymlinkAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $deployPath = $this->deployer->getDeployPath();

        $this->deployer->writeln("run (man mv 2>&1 || mv -h 2>&1 || mv --help 2>&1) | grep -- --no-target-directory || true");
        $supportsNoTarget = $this->deployer->run("(man mv 2>&1 || mv -h 2>&1 || mv --help 2>&1) | grep -- --no-target-directory || true");
        if (!empty($supportsNoTarget)) {
            $this->deployer->writeln("       -T, --no-target-directory");
        }

        $this->deployer->writeln("run mv -T {$deployPath}/release {$deployPath}/current");
        $this->deployer->run("mv -T {$deployPath}/release {$deployPath}/current");
    }

    public function getName(): string
    {
        return 'deploy:symlink';
    }
}
