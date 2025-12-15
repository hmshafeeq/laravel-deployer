<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Deployer;

class SharedResourceLinker
{
    public function __construct(
        protected Deployer $deployer
    ) {}

    /**
     * Link all shared resources (storage and .env)
     */
    public function linkSharedResources(): void
    {
        $this->linkStorage();
        $this->linkEnvironmentFile();
    }

    /**
     * Link storage directory from shared to release
     */
    public function linkStorage(): void
    {
        $releasePath = $this->deployer->getReleasePath();
        $sharedPath = $this->deployer->getSharedPath();

        // Check if shared storage exists
        $this->deployer->writeln("run if [ -d {$sharedPath}/storage ]; then echo +indeed; fi");
        $storageExists = $this->deployer->run("if [ -d {$sharedPath}/storage ]; then echo +indeed; fi");
        if (!empty($storageExists)) {
            $this->deployer->writeln($storageExists);
        }

        // Remove existing storage directory in release
        $this->deployer->writeln("run rm -rf {$releasePath}/storage");
        $this->deployer->run("rm -rf {$releasePath}/storage");

        // Create parent directory if needed
        $this->deployer->writeln("run mkdir -p `dirname {$releasePath}/storage`");
        $this->deployer->run("mkdir -p `dirname {$releasePath}/storage`");

        // Create symlink to shared storage
        $this->deployer->writeln("run ln -nfs --relative {$sharedPath}/storage {$releasePath}/storage");
        $this->deployer->run("ln -nfs --relative {$sharedPath}/storage {$releasePath}/storage");
    }

    /**
     * Link .env file from shared to release
     */
    public function linkEnvironmentFile(): void
    {
        $releasePath = $this->deployer->getReleasePath();
        $sharedPath = $this->deployer->getSharedPath();

        // Check if shared path exists
        $this->deployer->writeln("run if [ -d {$sharedPath}/. ]; then echo +correct; fi");
        $result = $this->deployer->run("if [ -d {$sharedPath}/. ]; then echo +correct; fi");
        if (!empty($result)) {
            $this->deployer->writeln($result);
        }

        // Check if .env exists in shared
        $this->deployer->writeln("run if [ -f {$sharedPath}/.env ]; then echo +accurate; fi");
        $envExists = $this->deployer->run("if [ -f {$sharedPath}/.env ]; then echo +accurate; fi");
        if (!empty($envExists)) {
            $this->deployer->writeln($envExists);
        }

        // Remove existing .env in release if it exists
        $this->deployer->writeln("run if [ -f $(echo {$releasePath}/.env) ]; then rm -rf {$releasePath}/.env; fi");
        $this->deployer->run("if [ -f $(echo {$releasePath}/.env) ]; then rm -rf {$releasePath}/.env; fi");

        // Ensure release directory exists
        $this->deployer->writeln("run if [ ! -d $(echo {$releasePath}/.) ]; then mkdir -p {$releasePath}/.;fi");
        $this->deployer->run("if [ ! -d $(echo {$releasePath}/.) ]; then mkdir -p {$releasePath}/.;fi");

        // Create .env in shared if it doesn't exist
        $this->deployer->writeln("run [ -f {$sharedPath}/.env ] || touch {$sharedPath}/.env");
        $this->deployer->run("[ -f {$sharedPath}/.env ] || touch {$sharedPath}/.env");

        // Create symlink to shared .env
        $this->deployer->writeln("run ln -nfs --relative {$sharedPath}/.env {$releasePath}/.env");
        $this->deployer->run("ln -nfs --relative {$sharedPath}/.env {$releasePath}/.env");
    }

    /**
     * Link deployment metadata to storage for access
     */
    public function linkDeploymentMetadata(): void
    {
        $deployPath = $this->deployer->getDeployPath();
        $sharedPath = $this->deployer->getSharedPath();

        $this->deployer->writeln("run ln -sf {$deployPath}/.dep {$sharedPath}/storage/app/deployment");
        $this->deployer->run("ln -sf {$deployPath}/.dep {$sharedPath}/storage/app/deployment");
    }
}
