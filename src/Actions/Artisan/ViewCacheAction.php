<?php

namespace Shaf\LaravelDeployer\Actions\Artisan;

class ViewCacheAction extends AbstractArtisanAction
{
    public function execute(): void
    {
        $this->runArtisan('view:cache');
    }

    public function getName(): string
    {
        return 'artisan:view:cache';
    }
}
