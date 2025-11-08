<?php

namespace Shaf\LaravelDeployer\Actions\Artisan;

class ConfigCacheAction extends AbstractArtisanAction
{
    public function execute(): void
    {
        $this->runArtisan('config:cache');
    }

    public function getName(): string
    {
        return 'artisan:config:cache';
    }
}
