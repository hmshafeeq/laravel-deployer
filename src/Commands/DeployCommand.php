<?php

namespace Shaf\LaravelDeployer\Commands;

use Shaf\LaravelDeployer\Actions\Deployment\ActivateReleaseAction;
use Shaf\LaravelDeployer\Actions\Deployment\BuildAssetsLocallyAction;
use Shaf\LaravelDeployer\Actions\Deployment\ConfigureReleaseAction;
use Shaf\LaravelDeployer\Actions\Deployment\OptimizeApplicationAction;
use Shaf\LaravelDeployer\Actions\Deployment\PrepareDeploymentAction;
use Shaf\LaravelDeployer\Actions\Deployment\RunPostDeploymentScriptsAction;
use Shaf\LaravelDeployer\Actions\Deployment\SyncCodeAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\CheckDiskSpaceAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\CheckHealthEndpointAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\CheckMemoryUsageAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\RunSmokeTestsAction;
use Shaf\LaravelDeployer\Actions\Notification\SendFailureNotificationAction;
use Shaf\LaravelDeployer\Actions\Notification\SendSuccessNotificationAction;
use Shaf\LaravelDeployer\Actions\Service\ReloadSupervisorAction;
use Shaf\LaravelDeployer\Actions\Service\RestartNginxAction;
use Shaf\LaravelDeployer\Actions\Service\RestartPhpFpmAction;
use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Services\LockManager;
use Shaf\LaravelDeployer\Services\SharedResourceLinker;

class DeployCommand extends BaseDeployerCommand
{
    protected $signature = 'deploy {environment=staging : The deployment environment (local, staging, production)}
                            {task=deploy : The deployment task to run (deploy, deploy:full, rollback:quick, etc.)}
                            {--no-confirm : Skip deployment confirmation}';

    protected $description = 'Deploy the application using Spatie SSH';

    protected Deployer $deployer;

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $task = $this->argument('task');
        $noConfirm = $this->option('no-confirm');

        // Validate environment
        if (!$this->validateEnvironment($environment)) {
            return self::FAILURE;
        }

        // Pre-deployment validations
        if (!$this->runPreDeploymentChecks()) {
            return self::FAILURE;
        }

        // Initialize deployer
        $this->deployer = $this->initDeployer($environment);

        // Execute deployment with error handling
        return $this->executeDeploymentWithErrorHandling($task, $noConfirm);
    }

    /**
     * Validate deployment environment
     *
     * @param string $environment
     * @return bool
     */
    protected function validateEnvironment(string $environment): bool
    {
        $validEnvironments = ['local', 'staging', 'production'];

        if (!in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: '.implode(', ', $validEnvironments));

            return false;
        }

        return true;
    }

    /**
     * Run pre-deployment checks
     *
     * @return bool True if all checks pass, false otherwise
     */
    protected function runPreDeploymentChecks(): bool
    {
        // Check if Vite is running
        if ($this->isViteRunning()) {
            $this->newLine();
            $this->components->error('Vite bundler is currently running!');
            $this->newLine();
            $this->components->warn('Please stop the Vite development server before deploying. 💡 Press Ctrl+C in the terminal where Vite is running to stop it.');
            $this->newLine();

            return false;
        }

        return true;
    }

    /**
     * Execute deployment with proper error handling
     *
     * @param string $task
     * @param bool $noConfirm
     * @return int
     */
    protected function executeDeploymentWithErrorHandling(string $task, bool $noConfirm): int
    {
        try {
            $this->info("Starting deployment: {$task} to {$this->deployer->get('environment')}");
            $this->newLine();

            switch ($task) {
                case 'deploy':
                    $this->runDeploy($noConfirm);
                    break;
                case 'deploy:full':
                    $this->runFullDeploy($noConfirm);
                    break;
                default:
                    $this->error("Unknown task: {$task}");

                    return self::FAILURE;
            }

            $this->newLine();
            $this->info('Deployment completed successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->handleDeploymentFailure($e);

            return self::FAILURE;
        }
    }

    /**
     * Handle deployment failure
     *
     * @param \Exception $e
     * @return void
     */
    protected function handleDeploymentFailure(\Exception $e): void
    {
        $this->newLine();
        $this->error('Deployment failed!');
        $this->error($e->getMessage());

        if ($this->getOutput()->isVerbose()) {
            $this->error($e->getTraceAsString());
        }

        // Send failure notification
        try {
            SendFailureNotificationAction::run($this->deployer);
        } catch (\Exception $notificationError) {
            // Silently fail notification errors
        }

        // Unlock deployment
        try {
            $lockManager = new LockManager($this->deployer);
            $lockManager->unlock();
        } catch (\Exception $unlockError) {
            // Silently fail unlock errors
        }
    }

    /**
     * Run standard deployment
     *
     * @param bool $noConfirm
     * @return void
     */
    protected function runDeploy(bool $noConfirm): void
    {
        // Confirm deployment
        if (!$this->deployer->confirmDeployment($noConfirm)) {
            throw new \RuntimeException('Deployment cancelled by user');
        }

        // Set up rsync configuration
        $this->configureRsync();

        // Generate release name
        $this->deployer->generateReleaseName();

        // Display deployment info
        $this->displayDeploymentInfo();

        // Execute deployment phases
        $this->runHealthChecks();
        $this->runDeploymentPhases();
        $this->runPostDeploymentPhases();
    }

    /**
     * Configure rsync excludes and includes
     *
     * @return void
     */
    protected function configureRsync(): void
    {
        $rsyncConfig = $this->deployer->get('rsync', []);
        $this->deployer->setRsyncExcludes($rsyncConfig['exclude'] ?? []);
        $this->deployer->setRsyncIncludes($rsyncConfig['include'] ?? []);
    }

    /**
     * Display deployment information
     *
     * @return void
     */
    protected function displayDeploymentInfo(): void
    {
        $user = $this->deployer->runLocally('git config --get user.name', false);
        $branch = $this->deployer->get('branch', 'HEAD');
        $releaseName = $this->deployer->getReleaseName();
        $this->deployer->writeln("info deploying {$branch} to {$this->deployer->get('hostname')} (release {$releaseName})");
    }

    /**
     * Run pre-deployment health checks
     *
     * @return void
     */
    protected function runHealthChecks(): void
    {
        $this->deployer->writeln("🔍 Checking server resources...");
        CheckDiskSpaceAction::run($this->deployer);
        CheckMemoryUsageAction::run($this->deployer);
        $this->deployer->writeln("");
    }

    /**
     * Run main deployment phases
     *
     * @return void
     */
    protected function runDeploymentPhases(): void
    {
        // Prepare deployment (setup, lock, create release)
        PrepareDeploymentAction::run($this->deployer);

        // Build assets locally
        BuildAssetsLocallyAction::run($this->deployer);

        // Sync code to server
        SyncCodeAction::run($this->deployer);

        // Configure release (shared resources, vendors, permissions)
        ConfigureReleaseAction::run($this->deployer);

        // Optimize application (artisan commands, migrations)
        OptimizeApplicationAction::run($this->deployer);

        // Restart services
        $this->restartServices();

        // Activate release (symlink, cleanup, unlock)
        ActivateReleaseAction::run($this->deployer);
    }

    /**
     * Restart server services
     *
     * @return void
     */
    protected function restartServices(): void
    {
        RestartPhpFpmAction::run($this->deployer);
        RestartNginxAction::run($this->deployer);
        ReloadSupervisorAction::run($this->deployer);
    }

    /**
     * Run post-deployment tasks
     *
     * @return void
     */
    protected function runPostDeploymentPhases(): void
    {
        // Post-deployment scripts
        RunPostDeploymentScriptsAction::run($this->deployer);

        // Health checks
        $this->runApplicationHealthChecks();

        // Link deployment metadata
        $resourceLinker = new SharedResourceLinker($this->deployer);
        $resourceLinker->linkDeploymentMetadata();

        // Send success notification
        SendSuccessNotificationAction::run($this->deployer);
    }

    /**
     * Run application health checks
     *
     * @return void
     */
    protected function runApplicationHealthChecks(): void
    {
        $appUrl = $this->getApplicationUrl();

        $this->deployer->writeln("🔍 Running deployment health checks...");
        $this->deployer->writeln("");

        CheckHealthEndpointAction::run($this->deployer, null, $appUrl);
        RunSmokeTestsAction::run($this->deployer, $appUrl);

        $this->deployer->writeln("");
        $this->deployer->writeln("✅ All health checks passed!");
    }

    /**
     * Get application URL from deployed application
     *
     * @return string
     */
    protected function getApplicationUrl(): string
    {
        $currentPath = $this->deployer->getCurrentPath();
        $this->deployer->writeln("run cd {$currentPath} && php artisan tinker --execute=\"echo config(\\\"app.url\\\");\"");
        $appUrl = $this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo config(\\\"app.url\\\");\"");
        $this->deployer->writeln($appUrl);

        return trim($appUrl);
    }

    /**
     * Run full deployment (with database backup)
     *
     * @param bool $noConfirm
     * @return void
     */
    protected function runFullDeploy(bool $noConfirm): void
    {
        // For now, full deploy is the same as regular deploy
        // You can add database backup tasks here later
        $this->runDeploy($noConfirm);
    }

    /**
     * Check if Vite development server is running
     *
     * @return bool
     */
    protected function isViteRunning(): bool
    {
        $process = \Symfony\Component\Process\Process::fromShellCommandline('ps aux');
        $process->run();

        if (!$process->isSuccessful()) {
            return false;
        }

        $output = $process->getOutput();
        $projectPath = base_path();

        // Look for vite processes running from this project's directory
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, 'node_modules/.bin/vite') && str_contains($line, $projectPath)) {
                return true;
            }
        }

        return false;
    }
}
