<?php

namespace Shaf\LaravelDeployer\Actions\Artisan;

class OptimizeAction extends AbstractArtisanAction
{
    public function execute(): void
    {
        $this->runArtisan('optimize');
    }

    public function getName(): string
    {
        return 'artisan:optimize';
    }
}
