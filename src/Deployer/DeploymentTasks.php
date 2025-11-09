<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Concerns\ExecutesCommands;
use Shaf\LaravelDeployer\Constants\Commands;
use Shaf\LaravelDeployer\Constants\Paths;
use Shaf\LaravelDeployer\Data\ReleaseInfo;
use Shaf\LaravelDeployer\Services\ArtisanTaskRunner;
use Shaf\LaravelDeployer\Services\LockManager;
use Shaf\LaravelDeployer\Services\ReleaseManager;
use Shaf\LaravelDeployer\Services\RsyncService;

class DeploymentTasks extends BaseTaskRunner
{
    use ExecutesCommands;

    protected ArtisanTaskRunner $artisan;
    protected LockManager $lockManager;
    protected ReleaseManager $releaseManager;
    protected ?RsyncService $rsyncService = null;

    public function setArtisanRunner(ArtisanTaskRunner $artisan): void
    {
        $this->artisan = $artisan;
    }

    public function setLockManager(LockManager $lockManager): void
    {
        $this->lockManager = $lockManager;
    }

    public function setReleaseManager(ReleaseManager $releaseManager): void
    {
        $this->releaseManager = $releaseManager;
    }

    public function setRsyncService(RsyncService $rsyncService): void
    {
        $this->rsyncService = $rsyncService;
    }

    public function deployInfo(): void
    {
        $this->task('deploy:info', function () {
            $user = $this->releaseManager->getUser();
            $branch = $this->getBranch();
            $releaseName = $this->getReleaseName();
            $hostname = $this->getHostname();

            $this->output->info("Deploying {$releaseName} to {$hostname} (branch: {$branch}, user: {$user})");
        });
    }

    public function setup(): void
    {
        $this->task('deploy:setup', function () {
            $deployPath = $this->getDeployPath();

            // Create main deploy directory
            $this->createDirectory($deployPath);

            // Create subdirectories
            $this->createDirectory("{$deployPath}/" . Paths::DEP_DIR);
            $this->createDirectory("{$deployPath}/" . Paths::RELEASES_DIR);
            $this->createDirectory("{$deployPath}/" . Paths::SHARED_DIR);

            // Check if current exists and is not a symlink
            if ($this->directoryExists("{$deployPath}/" . Paths::CURRENT_SYMLINK) &&
                !$this->symlinkExists("{$deployPath}/" . Paths::CURRENT_SYMLINK)) {
                $this->output->warning("Current directory exists but is not a symlink");
            }

            $this->output->success("Deployment structure initialized");
        });
    }

    public function checkLock(): void
    {
        $this->task('deploy:check-lock', function () {
            $this->lockManager->check();
        });
    }

    public function lock(): void
    {
        $this->task('deploy:lock', function () {
            $this->lockManager->lock();
        });
    }

    public function unlock(): void
    {
        $this->task('deploy:unlock', function () {
            $this->lockManager->unlock();
        });
    }

    public function release(): void
    {
        $this->task('deploy:release', function () {
            $deployPath = $this->getDeployPath();
            $releaseName = $this->getReleaseName();

            // Show existing releases if any
            $releases = $this->releaseManager->getReleases();
            if (!empty($releases)) {
                $this->output->debug("Existing releases: " . implode(', ', array_slice($releases, 0, 5)));
            }

            // Write to latest_release file
            $this->releaseManager->writeLatestRelease($releaseName);

            // Log the release
            $user = $this->releaseManager->getUser();
            $branch = $this->getBranch();
            $releaseInfo = ReleaseInfo::create($releaseName, $user, $branch);
            $this->releaseManager->logRelease($releaseInfo);

            // Create release directory
            $this->createDirectory($this->getReleasePath());

            // Create release symlink
            $this->createSymlink(
                Paths::RELEASES_DIR . '/' . $releaseName,
                "{$deployPath}/" . Paths::RELEASE_SYMLINK
            );

            $this->output->success("Release {$releaseName} created");
        });
    }

    public function buildAssets(): void
    {
        $this->task('build:assets', function () {
            $this->output->info("Building assets...");

            $command = 'npm run build';

            $process = \Symfony\Component\Process\Process::fromShellCommandline($command, base_path());
            $process->setTimeout(\Shaf\LaravelDeployer\Constants\Timeouts::NPM_BUILD);
            $process->run(function ($type, $buffer) {
                $this->output->commandOutput($buffer);
            });

            if (!$process->isSuccessful()) {
                throw new \RuntimeException("Asset build failed: " . $process->getErrorOutput());
            }

            $this->output->success("Assets built successfully");
        });
    }

    public function rsync(): void
    {
        $this->task('rsync', function () {
            if (!$this->rsyncService) {
                throw new \RuntimeException("RsyncService not initialized");
            }

            $this->rsyncService->sync($this->getReleasePath());
        });
    }

    public function shared(): void
    {
        $this->task('deploy:shared', function () {
            $releasePath = $this->getReleasePath();
            $sharedPath = $this->getSharedPath();

            // Link storage
            if ($this->directoryExists("{$sharedPath}/storage")) {
                $this->output->debug("Linking shared storage");
            }

            $this->removeDirectory("{$releasePath}/storage");
            $this->createSymlink("{$sharedPath}/storage", "{$releasePath}/storage");

            // Link .env
            if ($this->fileExists("{$sharedPath}/.env")) {
                $this->output->debug("Linking shared .env");
            } else {
                $this->touch("{$sharedPath}/.env");
            }

            $this->removeFile("{$releasePath}/.env");
            $this->createSymlink("{$sharedPath}/.env", "{$releasePath}/.env");

            $this->output->success("Shared files linked");
        });
    }

    public function writable(): void
    {
        $this->task('deploy:writable', function () {
            $releasePath = $this->getReleasePath();

            // Create writable directories
            $dirList = implode(' ', Paths::WRITABLE_DIRS);
            $this->run("cd {$releasePath} && mkdir -p {$dirList}");

            // Detect web server user
            $httpUser = $this->run("ps axo comm,user | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | sort | awk '{print \$NF}' | uniq || echo ''");
            $httpUser = trim($httpUser);

            if (empty($httpUser)) {
                $this->output->warning("Could not detect web server user");
                return;
            }

            $this->output->debug("Web server user: {$httpUser}");

            // Check if setfacl is available
            $hasSetfacl = $this->test("hash setfacl 2>/dev/null");

            if ($hasSetfacl) {
                $currentUser = $this->config->remoteUser;

                // Set ACLs for writable directories
                foreach (Paths::WRITABLE_DIRS as $dir) {
                    $fullPath = "{$releasePath}/{$dir}";

                    // Check if ACL already set
                    $aclCount = (int) trim($this->run("getfacl -p {$fullPath} 2>/dev/null | grep \"^user:{$httpUser}:.*w\" | wc -l || echo 0"));

                    if ($aclCount === 0) {
                        $this->run("setfacl -L -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX {$fullPath}");
                        $this->run("setfacl -dL -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX {$fullPath}");
                        $this->output->debug("ACL set for {$dir}");
                    }
                }
            }

            $this->output->success("Writable directories configured");
        });
    }

    public function vendors(): void
    {
        $this->task('deploy:vendors', function () {
            $releasePath = $this->getReleasePath();
            $composerOptions = $this->config->composerOptions;

            // Check if composer is available
            $hasComposer = $this->test("hash composer 2>/dev/null");

            if (!$hasComposer) {
                throw new \RuntimeException("Composer not found on remote server");
            }

            $composerPath = trim($this->run("command -v composer || which composer || type -p composer"));
            $phpPath = trim($this->run("command -v php || which php || type -p php"));

            $this->output->info("Installing composer dependencies...");

            $command = "cd {$releasePath} && {$phpPath} {$composerPath} install {$composerOptions} 2>&1";
            $result = $this->run($command);

            $this->output->success("Composer dependencies installed");
        });
    }

    public function fixModulePermissions(): void
    {
        $this->task('deploy:fix-module-permissions', function () {
            $releasePath = $this->getReleasePath();

            $this->output->info("🔒 Fixing permissions for modular structure...");

            // These commands can fail if app-modules doesn't exist
            $this->run("chmod -R 755 {$releasePath}/app-modules 2>/dev/null || true");
            $this->run("find {$releasePath}/app-modules -type f -exec chmod 644 {} \\; 2>/dev/null || true");
            $this->run("find {$releasePath}/app-modules -type d -exec chmod 755 {} \\; 2>/dev/null || true");

            $this->output->success("Module permissions fixed");
        });
    }

    public function symlink(): void
    {
        $this->task('deploy:symlink', function () {
            $deployPath = $this->getDeployPath();

            // Atomic symlink swap using mv -T
            $this->move(
                "{$deployPath}/" . Paths::RELEASE_SYMLINK,
                "{$deployPath}/" . Paths::CURRENT_SYMLINK,
                noTargetDirectory: true
            );

            $this->output->success("Symlink updated to current release");
        });
    }

    public function cleanup(): void
    {
        $this->task('deploy:cleanup', function () {
            $deployPath = $this->getDeployPath();
            $keepReleases = $this->config->keepReleases;

            // Remove release symlink
            $releaseSymlink = "{$deployPath}/" . Paths::RELEASE_SYMLINK;
            if ($this->pathExists($releaseSymlink)) {
                $this->removeFile($releaseSymlink);
            }

            // Get list of releases sorted by time, keep only the specified number
            $releases = $this->releaseManager->getReleases();

            if (count($releases) > $keepReleases) {
                $releasesToDelete = array_slice($releases, $keepReleases);

                foreach ($releasesToDelete as $release) {
                    $this->output->debug("Removing old release: {$release}");
                    $this->removeDirectory("{$deployPath}/" . Paths::RELEASES_DIR . "/{$release}");
                }

                $this->output->success("Cleaned up " . count($releasesToDelete) . " old release(s)");
            } else {
                $this->output->debug("No releases to clean up (keeping {$keepReleases})");
            }
        });
    }

    public function success(): void
    {
        $this->task('deploy:success', function () {
            $this->output->success("Deployment completed successfully!");
        });
    }

    public function linkDep(): void
    {
        $this->task('deploy:link-dep', function () {
            $deployPath = $this->getDeployPath();
            $sharedPath = $this->getSharedPath();

            $this->createSymlink(
                "{$deployPath}/" . Paths::DEP_DIR,
                "{$sharedPath}/storage/app/deployment"
            );

            $this->output->debug("Linked .dep directory to shared storage");
        });
    }

    public function postDeployment(): void
    {
        $this->task('post:deployment', function () {
            $currentPath = $this->getCurrentPath();

            // Publish log viewer assets
            $this->output->info("Publishing vendor assets...");
            $this->run("cd {$currentPath} && " . Commands::PHP_BINARY . " artisan vendor:publish --tag=log-viewer-assets --force 2>/dev/null || true");

            // Run post-deployment script if it exists
            $scriptPath = "{$currentPath}/post-deployment.sh";
            if ($this->fileExists($scriptPath)) {
                $this->output->info("Running post-deployment script...");
                $this->run("cd {$currentPath} && ./post-deployment.sh");
            }

            $this->output->success("Post-deployment tasks completed");
        });
    }

    // Artisan task methods - now using ArtisanTaskRunner
    public function artisanStorageLink(): void
    {
        $this->task('artisan:storage:link', function () {
            $this->artisan->storageLink();
        });
    }

    public function artisanConfigCache(): void
    {
        $this->task('artisan:config:cache', function () {
            $this->artisan->configCache();
        });
    }

    public function artisanViewCache(): void
    {
        $this->task('artisan:view:cache', function () {
            $this->artisan->viewCache();
        });
    }

    public function artisanRouteCache(): void
    {
        $this->task('artisan:route:cache', function () {
            $this->artisan->routeCache();
        });
    }

    public function artisanOptimize(): void
    {
        $this->task('artisan:optimize', function () {
            $this->artisan->optimize();
        });
    }

    public function artisanMigrate(): void
    {
        $this->task('artisan:migrate', function () {
            // Check if .env file has content before running migrations
            $envFile = $this->getReleasePath() . '/.env';
            if ($this->test("[ -s {$envFile} ]")) {
                $this->artisan->migrate(force: true);
            } else {
                $this->output->warning("Skipping migrations - .env file is empty");
            }
        });
    }

    public function artisanQueueRestart(): void
    {
        $this->task('artisan:queue:restart', function () {
            $this->artisan->queueRestart();
        });
    }

    // Rollback methods
    public function getReleases(): array
    {
        return $this->releaseManager->getReleases();
    }

    public function getCurrentRelease(): ?string
    {
        return $this->releaseManager->getCurrentRelease();
    }

    public function rollback(string $targetRelease): void
    {
        $deployPath = $this->getDeployPath();
        $releasesPath = "{$deployPath}/" . Paths::RELEASES_DIR;
        $targetPath = "{$releasesPath}/{$targetRelease}";
        $currentPath = "{$deployPath}/" . Paths::CURRENT_SYMLINK;

        $this->output->info("🔄 Rolling back to release: {$targetRelease}");

        // Verify target release exists
        if (!$this->directoryExists($targetPath)) {
            throw new \Shaf\LaravelDeployer\Exceptions\DeploymentException::releaseNotFound($targetRelease);
        }

        // Create release symlink
        $this->createSymlink($targetPath, "{$deployPath}/" . Paths::RELEASE_SYMLINK);

        // Atomic swap to new release
        $this->move(
            "{$deployPath}/" . Paths::RELEASE_SYMLINK,
            $currentPath,
            noTargetDirectory: true
        );

        $this->output->success("Symlink updated to: {$targetRelease}");
    }

    public function getRollbackInfo(): array
    {
        $releases = $this->getReleases();
        $currentRelease = $this->getCurrentRelease();

        $info = [
            'current' => $currentRelease,
            'releases' => $releases,
            'can_rollback' => false,
            'previous' => null,
        ];

        if ($currentRelease && count($releases) > 1) {
            $currentIndex = array_search($currentRelease, $releases);
            if ($currentIndex !== false && $currentIndex < count($releases) - 1) {
                $info['can_rollback'] = true;
                $info['previous'] = $releases[$currentIndex + 1];
            }
        }

        return $info;
    }
}
