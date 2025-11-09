<?php

namespace Shaf\LaravelDeployer\Actions\Maintenance;

use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Support\Abstract\Action;

/**
 * Clear Laravel application caches
 *
 * This action clears various Laravel caches on the remote server,
 * including config, view, route, and application cache.
 */
class ClearCachesAction extends Action
{
    /**
     * List of caches to clear
     *
     * @var array<string>
     */
    protected array $caches;

    /**
     * Create a new ClearCachesAction instance
     *
     * @param Deployer $deployer
     * @param array<string> $caches List of caches to clear (default: all)
     */
    public function __construct(
        protected Deployer $deployer,
        array $caches = ['config', 'view', 'route', 'cache']
    ) {
        $this->caches = $caches;
    }

    /**
     * Execute the cache clearing operation
     *
     * @return void
     */
    public function execute(): void
    {
        $this->writeln('🗑️  Clearing Laravel caches...', 'info');

        foreach ($this->caches as $cache) {
            $this->clearCache($cache);
        }
    }

    /**
     * Clear a specific cache
     *
     * @param string $cache Cache name (config, view, route, cache)
     * @return void
     */
    protected function clearCache(string $cache): void
    {
        try {
            $currentPath = $this->getCurrentPath();
            $this->cmd("cd {$currentPath} && php artisan {$cache}:clear");
            $this->writeln('  ✓ '.ucfirst($cache).' cache cleared', 'info');
        } catch (\Exception $e) {
            $this->writeln('  ⚠ '.ucfirst($cache).' cache clear failed', 'comment');
        }
    }
}
