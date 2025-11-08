<?php

namespace Shaf\LaravelDeployer\Actions\Artisan;

class StorageLinkAction extends AbstractArtisanAction
{
    public function execute(): void
    {
        $releasePath = $this->deployer->getReleasePath();
        $phpPath = $this->getPhpPath();

        $this->deployer->writeln("run {$phpPath} {$releasePath}/artisan --version");
        $version = $this->deployer->run("{$phpPath} {$releasePath}/artisan --version");
        $this->deployer->writeln($version);

        $this->runArtisan('storage:link');
    }

    public function getName(): string
    {
        return 'artisan:storage:link';
    }
}
