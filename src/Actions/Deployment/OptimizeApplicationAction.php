<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Services\ArtisanCommandRunner;
use Shaf\LaravelDeployer\Support\Abstract\DeploymentAction;

class OptimizeApplicationAction extends DeploymentAction
{
    public function __construct(
        protected Deployer $deployer,
        protected ?ArtisanCommandRunner $artisan = null
    ) {
        parent::__construct($deployer);
        $this->artisan = $artisan ?? new ArtisanCommandRunner($deployer);
    }

    public function execute(): void
    {
        $releasePath = $this->getReleasePath();

        // Create storage link
        $this->artisan->version($releasePath);
        $this->artisan->run('storage:link', $releasePath);

        // Cache configuration
        $this->artisan->run('config:cache', $releasePath);

        // Cache views
        $this->artisan->run('view:cache', $releasePath);

        // Cache routes
        $this->artisan->run('route:cache', $releasePath);

        // Optimize application
        $this->artisan->run('optimize', $releasePath);

        // Run migrations
        $this->runMigrations($releasePath);

        // Restart queue workers
        $this->artisan->run('queue:restart', $releasePath);
    }

    /**
     * Run database migrations
     */
    protected function runMigrations(string $releasePath): void
    {
        $this->artisan->checkEnv($releasePath);
        $this->artisan->run('migrate --force', $releasePath);
    }
}
