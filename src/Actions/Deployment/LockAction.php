<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class LockAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $user = $this->deployer->runLocally('git config --get user.name');
        $this->deployer->writeln("run git config --get user.name");
        $this->deployer->writeln($user);

        $lockFile = $this->deployer->getDeployPath() . '/.dep/deploy.lock';
        $this->deployer->writeln("run [ -f {$lockFile} ] && echo +locked || echo '{$user}' > {$lockFile}");
        $result = $this->deployer->run("[ -f {$lockFile} ] && echo +locked || echo '{$user}' > {$lockFile}");
        if (!empty($result)) {
            $this->deployer->writeln($result);
        }
    }

    public function getName(): string
    {
        return 'deploy:lock';
    }
}
