<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
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
            $notificationTasks = $this->factory->createNotificationTasks();
            $notificationTasks->failure();

            // Unlock deployment
            $deploymentTasks = $this->factory->createDeploymentTasks();
            $deploymentTasks->unlock();

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

        // Create task runners
        $deploymentTasks = $this->factory->createDeploymentTasks();
        $healthCheckTasks = $this->factory->createHealthCheckTasks();
        $serviceTasks = $this->factory->createServiceTasks();
        $notificationTasks = $this->factory->createNotificationTasks();

        // Run deployment tasks in order
        $deploymentTasks->deployInfo();
        $healthCheckTasks->checkResources();
        $deploymentTasks->setup();
        $deploymentTasks->checkLock();
        $deploymentTasks->lock();
        $deploymentTasks->release();
        $deploymentTasks->buildAssets();
        $deploymentTasks->rsync();
        $deploymentTasks->shared();
        $deploymentTasks->writable();
        $deploymentTasks->vendors();
        $deploymentTasks->fixModulePermissions();
        $deploymentTasks->artisanStorageLink();
        $deploymentTasks->artisanConfigCache();
        $deploymentTasks->artisanViewCache();
        $deploymentTasks->artisanRouteCache();
        $deploymentTasks->artisanOptimize();
        $deploymentTasks->artisanMigrate();
        $deploymentTasks->artisanQueueRestart();
        $serviceTasks->restartPhpFpm();
        $serviceTasks->restartNginx();
        $serviceTasks->reloadSupervisor();
        $deploymentTasks->symlink();
        $deploymentTasks->cleanup();
        $deploymentTasks->success();
        $deploymentTasks->postDeployment();
        $healthCheckTasks->checkEndpoints();
        $deploymentTasks->linkDep();
        $notificationTasks->success();
        $deploymentTasks->unlock();
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
