<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Actions\Deployment\PrepareDeploymentAction;
use Shaf\LaravelDeployer\Actions\Deployment\SyncCodeAction;
use Shaf\LaravelDeployer\Actions\Deployment\ConfigureReleaseAction;
use Shaf\LaravelDeployer\Actions\Deployment\OptimizeApplicationAction;
use Shaf\LaravelDeployer\Actions\Deployment\ActivateReleaseAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\CheckDiskSpaceAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\CheckMemoryUsageAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\CheckHealthEndpointAction;
use Shaf\LaravelDeployer\Actions\HealthCheck\RunSmokeTestsAction;
use Shaf\LaravelDeployer\Actions\Service\RestartPhpFpmAction;
use Shaf\LaravelDeployer\Actions\Service\RestartNginxAction;
use Shaf\LaravelDeployer\Actions\Service\ReloadSupervisorAction;
use Shaf\LaravelDeployer\Actions\Notification\SendSuccessNotificationAction;
use Shaf\LaravelDeployer\Actions\Notification\SendFailureNotificationAction;
use Shaf\LaravelDeployer\Services\LockManager;
use Shaf\LaravelDeployer\Services\SharedResourceLinker;
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
            SendFailureNotificationAction::run($this->deployer);

            // Unlock deployment
            $lockManager = new LockManager($this->deployer);
            $lockManager->unlock();

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

        // Display deployment info
        $this->displayDeploymentInfo();

        // Check server resources
        $this->deployer->writeln("🔍 Checking server resources...");
        CheckDiskSpaceAction::run($this->deployer);
        CheckMemoryUsageAction::run($this->deployer);
        $this->deployer->writeln("");

        // Prepare deployment (setup, lock, create release)
        PrepareDeploymentAction::run($this->deployer);

        // Build assets locally
        $this->deployer->runLocalCommand('npm run build');

        // Sync code to server
        SyncCodeAction::run($this->deployer);

        // Configure release (shared resources, vendors, permissions)
        ConfigureReleaseAction::run($this->deployer);

        // Optimize application (artisan commands, migrations)
        OptimizeApplicationAction::run($this->deployer);

        // Restart services
        RestartPhpFpmAction::run($this->deployer);
        RestartNginxAction::run($this->deployer);
        ReloadSupervisorAction::run($this->deployer);

        // Activate release (symlink, cleanup, unlock)
        ActivateReleaseAction::run($this->deployer);

        // Post-deployment tasks
        $this->runPostDeployment();

        // Health checks
        $appUrl = $this->getApplicationUrl();
        $this->deployer->writeln("🔍 Running deployment health checks...");
        $this->deployer->writeln("");
        CheckHealthEndpointAction::run($this->deployer, null, $appUrl);
        RunSmokeTestsAction::run($this->deployer, $appUrl);
        $this->deployer->writeln("");
        $this->deployer->writeln("✅ All health checks passed!");

        // Link deployment metadata
        $resourceLinker = new SharedResourceLinker($this->deployer);
        $resourceLinker->linkDeploymentMetadata();

        // Send success notification
        SendSuccessNotificationAction::run($this->deployer);
    }

    protected function displayDeploymentInfo(): void
    {
        $user = $this->deployer->runLocally('git config --get user.name', false);
        $branch = $this->deployer->get('branch', 'HEAD');
        $releaseName = $this->deployer->getReleaseName();
        $this->deployer->writeln("info deploying something to {$this->deployer->get('hostname')} (release {$releaseName})");
    }

    protected function runPostDeployment(): void
    {
        $currentPath = $this->deployer->getCurrentPath();
        $phpPath = config('laravel-deployer.php.executable');

        // Publish log viewer assets
        $this->deployer->writeln("run cd {$currentPath} && {$phpPath} artisan vendor:publish --tag=log-viewer-assets --force");
        $result = $this->deployer->run("cd {$currentPath} && {$phpPath} artisan vendor:publish --tag=log-viewer-assets --force");
        if (!empty($result)) {
            $lines = explode("\n", trim($result));
            foreach ($lines as $line) {
                $this->deployer->writeln($line);
            }
        }

        // Run post-deployment script if it exists
        $this->deployer->writeln("run cd {$currentPath} && ./post-deployment.sh");
        $result = $this->deployer->run("cd {$currentPath} && ./post-deployment.sh");
        if (!empty($result)) {
            $lines = explode("\n", trim($result));
            foreach ($lines as $line) {
                $this->deployer->writeln($line);
            }
        }
    }

    protected function getApplicationUrl(): string
    {
        $currentPath = $this->deployer->getCurrentPath();
        $this->deployer->writeln("run cd {$currentPath} && php artisan tinker --execute=\"echo config(\\\"app.url\\\");\"");
        $appUrl = $this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo config(\\\"app.url\\\");\"");
        $this->deployer->writeln($appUrl);
        return trim($appUrl);
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
