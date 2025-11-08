<?php

namespace Shaf\LaravelDeployer\Actions\Artisan;

class QueueRestartAction extends AbstractArtisanAction
{
    public function execute(): void
    {
        $this->runArtisan('queue:restart');
    }

    public function getName(): string
    {
        return 'artisan:queue:restart';
    }
}
