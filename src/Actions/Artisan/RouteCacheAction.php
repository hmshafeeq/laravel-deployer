<?php

namespace Shaf\LaravelDeployer\Actions\Artisan;

class RouteCacheAction extends AbstractArtisanAction
{
    public function execute(): void
    {
        $this->runArtisan('route:cache');
    }

    public function getName(): string
    {
        return 'artisan:route:cache';
    }
}
