<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;

class DeploymentOperationsService
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config,
        protected string $releaseName
    ) {
    }

    public function createSharedLinks(): void
    {
        $deployPath = $this->config->deployPath;
        $releasePath = "{$deployPath}/releases/{$this->releaseName}";
        $sharedPath = "{$deployPath}/shared";

        $this->output->info("Creating shared directory links...");

        // Link storage directory
        $this->executor->execute("rm -rf {$releasePath}/storage");
        $this->executor->execute("ln -nfs {$sharedPath}/storage {$releasePath}/storage");

        // Link .env file
        $this->executor->execute("ln -nfs {$sharedPath}/.env {$releasePath}/.env");

        $this->output->success("Shared links created");
    }

    public function setWritablePermissions(): void
    {
        $deployPath = $this->config->deployPath;
        $releasePath = "{$deployPath}/releases/{$this->releaseName}";

        $this->output->info("Setting writable permissions...");

        $writableDirs = [
            "{$releasePath}/bootstrap/cache",
            "{$releasePath}/storage",
            "{$releasePath}/storage/app",
            "{$releasePath}/storage/framework",
            "{$releasePath}/storage/logs",
        ];

        foreach ($writableDirs as $dir) {
            $this->executor->execute("chmod -R 775 {$dir} 2>/dev/null || true");
        }

        $this->output->success("Writable permissions set");
    }

    public function installComposerDependencies(): void
    {
        $deployPath = $this->config->deployPath;
        $releasePath = "{$deployPath}/releases/{$this->releaseName}";

        $this->output->info("Installing Composer dependencies...");

        $composerOptions = $this->config->composerOptions ?? '--verbose --prefer-dist --no-interaction --no-dev --optimize-autoloader';

        $this->executor->execute("cd {$releasePath} && composer install {$composerOptions}");

        $this->output->success("Composer dependencies installed");
    }

    public function fixModulePermissions(): void
    {
        $deployPath = $this->config->deployPath;
        $releasePath = "{$deployPath}/releases/{$this->releaseName}";

        $this->output->info("Fixing module permissions...");

        // Fix node_modules permissions if exists
        $this->executor->execute("chmod -R 755 {$releasePath}/node_modules 2>/dev/null || true");

        // Fix vendor permissions
        $this->executor->execute("chmod -R 755 {$releasePath}/vendor 2>/dev/null || true");

        $this->output->success("Module permissions fixed");
    }

    public function cleanupOldReleases(): void
    {
        $deployPath = $this->config->deployPath;
        $keepReleases = $this->config->keepReleases ?? 3;

        $this->output->info("Cleaning up old releases (keeping {$keepReleases})...");

        // List releases sorted by time, skip the most recent ones, remove the rest
        $this->executor->execute(
            "cd {$deployPath}/releases && ls -t | tail -n +".($keepReleases + 1)." | xargs -r rm -rf"
        );

        $remaining = trim($this->executor->execute("ls -1 {$deployPath}/releases | wc -l"));

        $this->output->success("Cleanup complete. {$remaining} releases remain");
    }

    public function linkDepDirectory(): void
    {
        $deployPath = $this->config->deployPath;
        $releasePath = "{$deployPath}/releases/{$this->releaseName}";

        $this->output->info("Linking .dep directory...");

        $this->executor->execute("ln -nfs {$deployPath}/.dep {$releasePath}/.dep");

        $this->output->success(".dep directory linked");
    }

    public function displayDeploymentInfo(): void
    {
        $this->output->info("═══════════════════════════════════════");
        $this->output->info("  Deployment Information");
        $this->output->info("═══════════════════════════════════════");
        $this->output->info("  Environment: {$this->config->environment->value}");
        $this->output->info("  Server: {$this->config->hostname}");
        $this->output->info("  User: {$this->config->remoteUser}");
        $this->output->info("  Path: {$this->config->deployPath}");
        $this->output->info("  Branch: {$this->config->branch}");
        $this->output->info("  Release: {$this->releaseName}");
        $this->output->info("═══════════════════════════════════════");
        $this->output->newLine();
    }

    public function logDeploymentSuccess(): void
    {
        $deployPath = $this->config->deployPath;
        $logFile = "{$deployPath}/.dep/deploy.log";

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] Deployed release {$this->releaseName} to {$this->config->environment->value}\n";

        $this->executor->execute("echo '{$logEntry}' >> {$logFile}");

        $this->output->success("✅ Deployment successful!");
        $this->output->success("🚀 Release {$this->releaseName} is now live on {$this->config->environment->value}");
    }

    public function runPostDeploymentHooks(): void
    {
        $deployPath = $this->config->deployPath;
        $hookScript = "{$deployPath}/.dep/post-deploy.sh";

        // Check if post-deploy hook exists
        $exists = trim($this->executor->execute("test -f {$hookScript} && echo 'OK' || echo 'FAIL'"));

        if ($exists === 'OK') {
            $this->output->info("Running post-deployment hooks...");
            $this->executor->execute("bash {$hookScript}");
            $this->output->success("Post-deployment hooks completed");
        }
    }
}
