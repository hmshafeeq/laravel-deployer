<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Services\LockManager;
use Shaf\LaravelDeployer\Services\ReleaseManager;
use Shaf\LaravelDeployer\Support\Abstract\DeploymentAction;
use Shaf\LaravelDeployer\Deployer\Deployer;

class PrepareDeploymentAction extends DeploymentAction
{
    public function __construct(
        protected Deployer $deployer,
        protected ?LockManager $lockManager = null,
        protected ?ReleaseManager $releaseManager = null
    ) {
        parent::__construct($deployer);
        $this->lockManager = $lockManager ?? new LockManager($deployer);
        $this->releaseManager = $releaseManager ?? new ReleaseManager($deployer);
    }

    public function execute(): string
    {
        $this->displayDeploymentInfo();
        $this->setupDeploymentStructure();
        $this->lockManager->checkLock();
        $this->lockManager->lock();

        $releaseName = $this->deployer->getReleaseName();
        $this->releaseManager->displayReleaseInfo();

        $releasePath = $this->releaseManager->createRelease($releaseName);

        return $releasePath;
    }

    /**
     * Display deployment information
     */
    protected function displayDeploymentInfo(): void
    {
        $user = $this->deployer->runLocally('git config --get user.name', false);
        $branch = $this->deployer->get('branch', 'HEAD');
        $releaseName = $this->deployer->getReleaseName();

        $this->writeln("info deploying something to {$this->deployer->get('hostname')} (release {$releaseName})");
    }

    /**
     * Setup the deployment directory structure
     */
    protected function setupDeploymentStructure(): void
    {
        $deployPath = $this->getDeployPath();

        $this->writeln("run [ -d {$deployPath} ] || mkdir -p {$deployPath};");
        $this->run("[ -d {$deployPath} ] || mkdir -p {$deployPath}");

        $this->writeln("run cd {$deployPath};");
        $this->run("cd {$deployPath}");

        $this->writeln("run [ -d .dep ] || mkdir .dep;");
        $this->run("cd {$deployPath} && [ -d .dep ] || mkdir .dep");

        $this->writeln("run [ -d releases ] || mkdir releases;");
        $this->run("cd {$deployPath} && [ -d releases ] || mkdir releases");

        $this->writeln("run [ -d shared ] || mkdir shared;");
        $this->run("cd {$deployPath} && [ -d shared ] || mkdir shared");

        // Check if current exists and is not a symlink
        $this->writeln("run if [ ! -L {$deployPath}/current ] && [ -d {$deployPath}/current ]; then echo +appropriate; fi");
        $result = $this->run("if [ ! -L {$deployPath}/current ] && [ -d {$deployPath}/current ]; then echo +appropriate; fi");
        if (!empty($result)) {
            $this->writeln($result);
        }
    }
}
