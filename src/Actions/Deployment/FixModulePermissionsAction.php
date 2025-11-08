<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class FixModulePermissionsAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $releasePath = $this->deployer->getReleasePath();

        $this->deployer->writeln("🔒 Fixing permissions for modular structure...");

        $this->deployer->writeln("run chmod -R 755 {$releasePath}/app-modules || true");
        $this->deployer->run("chmod -R 755 {$releasePath}/app-modules || true");

        $this->deployer->writeln("run find {$releasePath}/app-modules -type f -exec chmod 644 {} \\; || true");
        $this->deployer->run("find {$releasePath}/app-modules -type f -exec chmod 644 {} \\; || true");

        $this->deployer->writeln("run find {$releasePath}/app-modules -type d -exec chmod 755 {} \\; || true");
        $this->deployer->run("find {$releasePath}/app-modules -type d -exec chmod 755 {} \\; || true");

        $this->deployer->writeln("✅ Module permissions fixed");
    }

    public function getName(): string
    {
        return 'deploy:fix-module-permissions';
    }
}
