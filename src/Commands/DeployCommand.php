<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Deployer\Deployer;
use Shaf\LaravelDeployer\Deployer\DeploymentTasks;
use Shaf\LaravelDeployer\Deployer\HealthCheckTasks;
use Shaf\LaravelDeployer\Deployer\NotificationTasks;
use Shaf\LaravelDeployer\Deployer\ServiceTasks;
use Symfony\Component\Yaml\Yaml;

class DeployCommand extends Command
{
    protected $signature = 'deploy {environment=staging : The deployment environment (local, staging, production)}
                            {task=deploy : The deployment task to run (deploy, deploy:full, rollback:quick, etc.)}
                            {--no-confirm : Skip deployment confirmation}';

    protected $description = 'Deploy the application using Spatie SSH';

    protected Deployer $deployer;
    protected array $config;

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

        // Load configuration
        $this->loadConfiguration($environment);

        // Create deployer instance
        $this->deployer = new Deployer($environment, $this->config);

        try {
            // Load environment variables
            $this->deployer->loadEnvironment();

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
            $notificationTasks = new NotificationTasks($this->deployer);
            $notificationTasks->failure();

            // Unlock deployment
            $deploymentTasks = new DeploymentTasks($this->deployer);
            $deploymentTasks->unlock();

            return self::FAILURE;
        }
    }

    protected function loadConfiguration(string $environment): void
    {
        $yamlPath = base_path('deploy.yaml');

        if (!file_exists($yamlPath)) {
            throw new \RuntimeException("Configuration file not found: {$yamlPath}");
        }

        $yaml = Yaml::parseFile($yamlPath);

        // Load environment-specific configuration
        $hostConfig = $yaml['hosts'][$environment] ?? [];

        $this->config = [
            'environment' => $environment,
            'hostname' => $hostConfig['hostname'] ?? 'localhost',
            'remote_user' => $hostConfig['remote_user'] ?? 'deploy',
            'deploy_path' => $hostConfig['deploy_path'] ?? '/var/www/app',
            'branch' => $hostConfig['branch'] ?? 'main',
            'composer_options' => $hostConfig['composer_options'] ?? '--verbose --prefer-dist --no-interaction --no-scripts --optimize-autoloader',
            'keep_releases' => $yaml['config']['keep_releases'] ?? 3,
            'local' => $hostConfig['local'] ?? false,
            'application' => $yaml['config']['application'] ?? 'Application',
            'rsync' => $yaml['config']['rsync'] ?? [],
        ];
    }

    protected function runDeploy(bool $noConfirm): void
    {
        // Confirm deployment
        if (!$this->deployer->confirmDeployment($noConfirm)) {
            throw new \RuntimeException('Deployment cancelled by user');
        }

        // Set up rsync excludes and includes
        $rsyncConfig = $this->config['rsync'];
        $this->deployer->setRsyncExcludes($rsyncConfig['exclude'] ?? []);
        $this->deployer->setRsyncIncludes($rsyncConfig['include'] ?? []);

        // Generate release name
        $this->deployer->generateReleaseName();

        // Create task runners
        $deploymentTasks = new DeploymentTasks($this->deployer);
        $healthCheckTasks = new HealthCheckTasks($this->deployer);
        $serviceTasks = new ServiceTasks($this->deployer);
        $notificationTasks = new NotificationTasks($this->deployer);

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
