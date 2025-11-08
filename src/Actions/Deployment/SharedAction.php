<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class SharedAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $deployPath = $this->deployer->getDeployPath();
        $releasePath = $this->deployer->getReleasePath();
        $sharedPath = $this->deployer->getSharedPath();

        // Link storage
        $this->deployer->writeln("run if [ -d {$sharedPath}/storage ]; then echo +indeed; fi");
        $storageExists = $this->deployer->run("if [ -d {$sharedPath}/storage ]; then echo +indeed; fi");
        if (!empty($storageExists)) {
            $this->deployer->writeln($storageExists);
        }

        $this->deployer->writeln("run rm -rf {$releasePath}/storage");
        $this->deployer->run("rm -rf {$releasePath}/storage");

        $this->deployer->writeln("run mkdir -p `dirname {$releasePath}/storage`");
        $this->deployer->run("mkdir -p `dirname {$releasePath}/storage`");

        $this->deployer->writeln("run ln -nfs --relative {$sharedPath}/storage {$releasePath}/storage");
        $this->deployer->run("ln -nfs --relative {$sharedPath}/storage {$releasePath}/storage");

        // Link .env
        $this->deployer->writeln("run if [ -d {$sharedPath}/. ]; then echo +correct; fi");
        $result = $this->deployer->run("if [ -d {$sharedPath}/. ]; then echo +correct; fi");
        if (!empty($result)) {
            $this->deployer->writeln($result);
        }

        $this->deployer->writeln("run if [ -f {$sharedPath}/.env ]; then echo +accurate; fi");
        $envExists = $this->deployer->run("if [ -f {$sharedPath}/.env ]; then echo +accurate; fi");
        if (!empty($envExists)) {
            $this->deployer->writeln($envExists);
        }

        $this->deployer->writeln("run if [ -f $(echo {$releasePath}/.env) ]; then rm -rf {$releasePath}/.env; fi");
        $this->deployer->run("if [ -f $(echo {$releasePath}/.env) ]; then rm -rf {$releasePath}/.env; fi");

        $this->deployer->writeln("run if [ ! -d $(echo {$releasePath}/.) ]; then mkdir -p {$releasePath}/.;fi");
        $this->deployer->run("if [ ! -d $(echo {$releasePath}/.) ]; then mkdir -p {$releasePath}/.;fi");

        $this->deployer->writeln("run [ -f {$sharedPath}/.env ] || touch {$sharedPath}/.env");
        $this->deployer->run("[ -f {$sharedPath}/.env ] || touch {$sharedPath}/.env");

        $this->deployer->writeln("run ln -nfs --relative {$sharedPath}/.env {$releasePath}/.env");
        $this->deployer->run("ln -nfs --relative {$sharedPath}/.env {$releasePath}/.env");
    }

    public function getName(): string
    {
        return 'deploy:shared';
    }
}
