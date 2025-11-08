<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Services\ArtisanCommandRunner;
use Shaf\LaravelDeployer\Services\SystemCommandDetector;
use Shaf\LaravelDeployer\Services\ReleaseManager;
use Shaf\LaravelDeployer\Services\LockManager;
use Shaf\LaravelDeployer\Actions\Deployment\PrepareDeploymentAction;
use Shaf\LaravelDeployer\Actions\Deployment\SyncCodeAction;
use Shaf\LaravelDeployer\Actions\Deployment\ConfigureReleaseAction;
use Shaf\LaravelDeployer\Actions\Deployment\OptimizeApplicationAction;
use Shaf\LaravelDeployer\Actions\Deployment\ActivateReleaseAction;
use Shaf\LaravelDeployer\Actions\Deployment\RollbackDeploymentAction;

class DeploymentTasks
{
    protected Deployer $deployer;
    protected ArtisanCommandRunner $artisan;
    protected SystemCommandDetector $systemDetector;
    protected ReleaseManager $releaseManager;
    protected LockManager $lockManager;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
        $this->artisan = new ArtisanCommandRunner($deployer);
        $this->systemDetector = new SystemCommandDetector($deployer);
        $this->releaseManager = new ReleaseManager($deployer);
        $this->lockManager = new LockManager($deployer);
    }

    /**
     * Prepare deployment: setup structure, lock, and create release
     */
    public function prepare(): void
    {
        $this->deployer->task('deploy:prepare', function () {
            PrepareDeploymentAction::run($this->deployer);
        });
    }

    public function checkLock(): void
    {
        $this->deployer->task('deploy:check-lock', function () {
            $this->lockManager->checkLock();
        });
    }

    public function lock(): void
    {
        $this->deployer->task('deploy:lock', function () {
            $this->lockManager->lock();
        });
    }

    public function unlock(): void
    {
        $this->deployer->task('deploy:unlock', function () {
            $this->lockManager->unlock();
        });
    }

    // Legacy methods for backward compatibility
    public function deployInfo(): void
    {
        $this->deployer->task('deploy:info', function ($deployer) {
            $user = $deployer->runLocally('git config --get user.name', false);
            $branch = $deployer->get('branch', 'HEAD');
            $releaseName = $deployer->getReleaseName();
            $deployer->writeln("info deploying something to {$deployer->get('hostname')} (release {$releaseName})");
        });
    }

    public function setup(): void
    {
        $this->prepare();
    }

    public function release(): void
    {
        $this->prepare();
    }

    public function buildAssets(): void
    {
        $this->deployer->task('build:assets', function ($deployer) {
            $deployer->runLocalCommand('npm run build');
        });
    }

    /**
     * Sync code to release directory using rsync
     */
    public function rsync(): void
    {
        $this->deployer->task('rsync', function () {
            SyncCodeAction::run($this->deployer);
        });
    }

    /**
     * Configure release: link shared resources, install vendors, set permissions
     */
    public function configure(): void
    {
        $this->deployer->task('deploy:configure', function () {
            ConfigureReleaseAction::run($this->deployer);
        });
    }

    /**
     * Optimize application: run artisan optimizations and migrations
     */
    public function optimize(): void
    {
        $this->deployer->task('deploy:optimize', function () {
            OptimizeApplicationAction::run($this->deployer);
        });
    }

    /**
     * Activate release: create symlink, cleanup old releases, unlock
     */
    public function activate(): void
    {
        $this->deployer->task('deploy:activate', function () {
            ActivateReleaseAction::run($this->deployer);
        });
    }

    // Legacy methods for backward compatibility
    public function shared(): void
    {
        $this->configure();
    }

    public function writable(): void
    {
        $this->configure();
    }

    public function vendors(): void
    {
        $this->configure();
    }

    public function fixModulePermissions(): void
    {
        $this->configure();
    }

    public function symlink(): void
    {
        $this->activate();
    }

    public function cleanup(): void
    {
        $this->activate();
    }

    public function success(): void
    {
        $this->deployer->task('deploy:success', function ($deployer) {
            $deployer->writeln("info successfully deployed!");
        });
    }

    public function linkDep(): void
    {
        $this->activate();
    }

    public function postDeployment(): void
    {
        $this->deployer->task('post:deployment', function ($deployer) {
            $currentPath = $deployer->getCurrentPath();
            $phpPath = config('laravel-deployer.php.executable');

            // Publish log viewer assets
            $deployer->writeln("run cd {$currentPath} && {$phpPath} artisan vendor:publish --tag=log-viewer-assets --force");
            $result = $deployer->run("cd {$currentPath} && {$phpPath} artisan vendor:publish --tag=log-viewer-assets --force");
            if (!empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }

            // Run post-deployment script if it exists
            $deployer->writeln("run cd {$currentPath} && ./post-deployment.sh");
            $result = $deployer->run("cd {$currentPath} && ./post-deployment.sh");
            if (!empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }
        });
    }

    // ======================================================================
    // Artisan Commands - Refactored using ArtisanCommandRunner
    // ======================================================================

    public function artisanStorageLink(): void
    {
        $this->deployer->task('artisan:storage:link', function ($deployer) {
            $releasePath = $deployer->getReleasePath();
            $this->artisan->version($releasePath);
            $this->artisan->run('storage:link', $releasePath);
        });
    }

    public function artisanConfigCache(): void
    {
        $this->deployer->task('artisan:config:cache', function ($deployer) {
            $this->artisan->run('config:cache', $deployer->getReleasePath());
        });
    }

    public function artisanViewCache(): void
    {
        $this->deployer->task('artisan:view:cache', function ($deployer) {
            $this->artisan->run('view:cache', $deployer->getReleasePath());
        });
    }

    public function artisanRouteCache(): void
    {
        $this->deployer->task('artisan:route:cache', function ($deployer) {
            $this->artisan->run('route:cache', $deployer->getReleasePath());
        });
    }

    public function artisanOptimize(): void
    {
        $this->deployer->task('artisan:optimize', function ($deployer) {
            $this->artisan->run('optimize', $deployer->getReleasePath());
        });
    }

    public function artisanMigrate(): void
    {
        $this->deployer->task('artisan:migrate', function ($deployer) {
            $releasePath = $deployer->getReleasePath();
            $this->artisan->checkEnv($releasePath);
            $this->artisan->run('migrate --force', $releasePath);
        });
    }

    public function artisanQueueRestart(): void
    {
        $this->deployer->task('artisan:queue:restart', function ($deployer) {
            $this->artisan->run('queue:restart', $deployer->getReleasePath());
        });
    }

    // ======================================================================
    // Release Management
    // ======================================================================

    /**
     * Get list of all releases sorted by time (newest first)
     */
    public function getReleases(): array
    {
        return $this->releaseManager->getReleases();
    }

    /**
     * Get the current release name
     */
    public function getCurrentRelease(): ?string
    {
        return $this->releaseManager->getCurrentRelease();
    }

    /**
     * Rollback to a specific release
     */
    public function rollback(?string $targetRelease = null): void
    {
        RollbackDeploymentAction::run($this->deployer, $this->releaseManager, $targetRelease);
    }

    /**
     * Get rollback information
     */
    public function getRollbackInfo(): array
    {
        return $this->releaseManager->getRollbackInfo();
    }
}
