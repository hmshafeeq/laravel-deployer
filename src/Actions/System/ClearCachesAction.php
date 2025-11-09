<?php

namespace Shaf\LaravelDeployer\Actions\System;

use Shaf\LaravelDeployer\Services\ArtisanTaskRunner;

class ClearCachesAction
{
    public function __construct(
        protected ArtisanTaskRunner $artisan
    ) {
    }

    public function execute(): array
    {
        $results = [];

        try {
            $this->artisan->run('config:cache');
            $results['config'] = true;
        } catch (\Exception $e) {
            $results['config'] = false;
        }

        try {
            $this->artisan->run('view:cache');
            $results['view'] = true;
        } catch (\Exception $e) {
            $results['view'] = false;
        }

        try {
            $this->artisan->run('route:cache');
            $results['route'] = true;
        } catch (\Exception $e) {
            $results['route'] = false;
        }

        try {
            $this->artisan->run('queue:restart');
            $results['queue'] = true;
        } catch (\Exception $e) {
            $results['queue'] = false;
        }

        return $results;
    }
}
