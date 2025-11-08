<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class SetupAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $deployPath = $this->deployer->getDeployPath();

        $this->deployer->writeln("run [ -d {$deployPath} ] || mkdir -p {$deployPath};");
        $this->deployer->run("[ -d {$deployPath} ] || mkdir -p {$deployPath}");

        $this->deployer->writeln("run cd {$deployPath};");
        $this->deployer->run("cd {$deployPath}");

        $this->deployer->writeln("run [ -d .dep ] || mkdir .dep;");
        $this->deployer->run("cd {$deployPath} && [ -d .dep ] || mkdir .dep");

        $this->deployer->writeln("run [ -d releases ] || mkdir releases;");
        $this->deployer->run("cd {$deployPath} && [ -d releases ] || mkdir releases");

        $this->deployer->writeln("run [ -d shared ] || mkdir shared;");
        $this->deployer->run("cd {$deployPath} && [ -d shared ] || mkdir shared");

        // Check if current exists and is not a symlink
        $this->deployer->writeln("run if [ ! -L {$deployPath}/current ] && [ -d {$deployPath}/current ]; then echo +appropriate; fi");
        $result = $this->deployer->run("if [ ! -L {$deployPath}/current ] && [ -d {$deployPath}/current ]; then echo +appropriate; fi");
        if (!empty($result)) {
            $this->deployer->writeln($result);
        }
    }

    public function getName(): string
    {
        return 'deploy:setup';
    }
}
