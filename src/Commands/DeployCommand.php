<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Actions\Deployment\BuildAssetsAction;
use Shaf\LaravelDeployer\Actions\Deployment\CreateReleaseAction;
use Shaf\LaravelDeployer\Actions\Deployment\LockDeploymentAction;
use Shaf\LaravelDeployer\Actions\Deployment\SetupDeploymentStructureAction;
use Shaf\LaravelDeployer\Actions\Deployment\SymlinkReleaseAction;
use Shaf\LaravelDeployer\Actions\Deployment\SyncFilesAction;
use Shaf\LaravelDeployer\Actions\Health\CheckEndpointsAction;
use Shaf\LaravelDeployer\Actions\Health\CheckServerResourcesAction;
use Shaf\LaravelDeployer\Actions\Notification\SendFailureNotificationAction;
use Shaf\LaravelDeployer\Actions\Notification\SendSuccessNotificationAction;
use Shaf\LaravelDeployer\Actions\System\ReloadSupervisorAction;
use Shaf\LaravelDeployer\Actions\System\RestartNginxAction;
use Shaf\LaravelDeployer\Actions\System\RestartPhpFpmAction;
use Shaf\LaravelDeployer\Services\DeploymentOperationsService;
use Shaf\LaravelDeployer\Services\DeploymentServiceFactory;

class DeployCommand extends Command
{
    protected $signature = 'deploy {environment=staging : The deployment environment (local, staging, production)}
                            {task=deploy : The deployment task to run (deploy, deploy:full, rollback:quick, etc.)}
                            {--no-confirm : Skip deployment confirmation}';

    protected $description = 'Deploy the application using Spatie SSH';

    protected DeploymentServiceFactory $factory;

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $task = $this->argument('task');
        $noConfirm = $this->option('no-confirm');

        $validEnvironments = ['local', 'staging', 'production'];
        if (!in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: ' . implode(', ', $validEnvironments));

            return self::FAILURE;
        }

        // Check if Vite is running
        if ($this->isViteRunning()) {
            $this->newLine();
            $this->components->error('Vite bundler is currently running!');
            $this->newLine();
            $this->components->warn('Please stop the Vite development server before deploying. 💡 Press Ctrl+C in the terminal where Vite is running to stop it.');
            $this->newLine();

            return self::FAILURE;
        }

        // Create factory and initialize for environment
        $this->factory = new DeploymentServiceFactory(
            base_path(),
            $this->output
        );
        $this->factory->createForEnvironment($environment);

        try {
            // Run the requested task
            $this->info("Starting deployment: {$task} to {$environment}");
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
            $this->newLine();
            $this->error('Deployment failed!');
            $this->error($e->getMessage());

            // Send failure notification
            $failureNotification = new SendFailureNotificationAction(
                $this->factory->createCommandExecutor(),
                $this->factory->getOutput(),
                $this->factory->getConfig(),
                $e->getMessage()
            );
            $failureNotification->execute();

            // Unlock deployment
            $lockFile = $this->factory->getConfig()->deployPath . '/.dep/deploy.lock';
            $lockAction = new LockDeploymentAction(
                $this->factory->createCommandExecutor(),
                $this->factory->getOutput(),
                $lockFile
            );
            $lockAction->unlock();

            return self::FAILURE;
        }
    }

    protected function runDeploy(bool $noConfirm): void
    {
        // Confirm deployment
        if (!$this->factory->confirmDeployment($noConfirm)) {
            throw new \RuntimeException('Deployment cancelled by user');
        }

        // Generate release name
        $this->factory->generateReleaseName();
        $releaseName = $this->factory->getReleaseName();

        // Create deployment operations service
        $deployOps = new DeploymentOperationsService(
            $this->factory->createCommandExecutor(),
            $this->factory->getOutput(),
            $this->factory->getConfig(),
            $releaseName
        );

        // Get lock file path
        $lockFile = $this->factory->getConfig()->deployPath . '/.dep/deploy.lock';

        // Display deployment info
        $deployOps->displayDeploymentInfo();

        // Check server resources
        $checkResources = new CheckServerResourcesAction(
            $this->factory->createCommandExecutor(),
            $this->factory->getOutput()
        );
        $checkResources->execute();

        // Setup deployment structure
        $setup = new SetupDeploymentStructureAction(
            $this->factory->createCommandExecutor(),
            $this->factory->getOutput(),
            $this->factory->getConfig()
        );
        $setup->execute();

        // Check and create deployment lock
        $lockAction = new LockDeploymentAction(
            $this->factory->createCommandExecutor(),
            $this->factory->getOutput(),
            $lockFile
        );
        $lockAction->check();
        $lockAction->lock();

        // Create release directory
        $createRelease = new CreateReleaseAction(
            $this->factory->createCommandExecutor(),
            $this->factory->getOutput(),
            $this->factory->getConfig(),
            $releaseName
        );
        $createRelease->execute();

        // Build assets locally
        $buildAssets = new BuildAssetsAction(
            $this->factory->createCommandExecutor(),
            $this->factory->getOutput(),
            base_path()
        );
        $buildAssets->execute();

        // Sync files to server
        $syncFiles = new SyncFilesAction(
            $this->factory->createRsyncService(),
            $this->factory->getOutput(),
            $this->factory->getConfig(),
            $releaseName
        );
        $syncFiles->execute();

        // Create shared links
        $deployOps->createSharedLinks();

        // Set writable permissions
        $deployOps->setWritablePermissions();

        // Install composer dependencies
        $deployOps->installComposerDependencies();

        // Fix module permissions
        $deployOps->fixModulePermissions();

        // Run artisan commands
        $artisan = $this->factory->createArtisanTaskRunner();

        $artisan->storageLink();
        $artisan->configCache();
        $artisan->viewCache();
        $artisan->routeCache();
        $artisan->optimize();
        $artisan->migrate();
        $artisan->queueRestart();

        // Restart services (if not local)
        if (!$this->factory->getConfig()->isLocal) {
            $restartPhpFpm = new RestartPhpFpmAction(
                $this->factory->createCommandExecutor(),
                $this->factory->getOutput()
            );
            $restartPhpFpm->execute();

            $restartNginx = new RestartNginxAction(
                $this->factory->createCommandExecutor(),
                $this->factory->getOutput()
            );
            $restartNginx->execute();

            $reloadSupervisor = new ReloadSupervisorAction(
                $this->factory->createCommandExecutor(),
                $this->factory->getOutput()
            );
            $reloadSupervisor->execute();
        }

        // Symlink new release
        $symlink = new SymlinkReleaseAction(
            $this->factory->createCommandExecutor(),
            $this->factory->getOutput(),
            $this->factory->getConfig(),
            $releaseName
        );
        $symlink->execute();

        // Cleanup old releases
        $deployOps->cleanupOldReleases();

        // Log deployment success
        $deployOps->logDeploymentSuccess();

        // Run post-deployment hooks
        $deployOps->runPostDeploymentHooks();

        // Check endpoints
        $checkEndpoints = new CheckEndpointsAction(
            $this->factory->createCommandExecutor(),
            $this->factory->getOutput(),
            $this->factory->getConfig()
        );
        $checkEndpoints->execute();

        // Link .dep directory
        $deployOps->linkDepDirectory();

        // Send success notification
        $successNotification = new SendSuccessNotificationAction(
            $this->factory->createCommandExecutor(),
            $this->factory->getOutput(),
            $this->factory->getConfig(),
            $releaseName
        );
        $successNotification->execute();

        // Unlock deployment
        $lockAction->unlock();
    }

    protected function runFullDeploy(bool $noConfirm): void
    {
        // For now, full deploy is the same as regular deploy
        // You can add database backup tasks here later
        $this->runDeploy($noConfirm);
    }

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
