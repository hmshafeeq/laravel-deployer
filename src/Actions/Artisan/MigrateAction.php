<?php

namespace Shaf\LaravelDeployer\Actions\Artisan;

class MigrateAction extends AbstractArtisanAction
{
    public function execute(): void
    {
        $releasePath = $this->deployer->getReleasePath();

        $this->deployer->writeln("run if [ -s {$releasePath}/.env ]; then echo +accurate; fi");
        $hasEnv = $this->deployer->run("if [ -s {$releasePath}/.env ]; then echo +accurate; fi");
        if (!empty($hasEnv)) {
            $this->deployer->writeln($hasEnv);
        }

        $this->runArtisan('migrate --force');
    }

    public function getName(): string
    {
        return 'artisan:migrate';
    }
}
