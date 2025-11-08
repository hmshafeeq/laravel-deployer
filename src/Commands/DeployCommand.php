<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Actions\Deployment\DeployInfoAction;
use Shaf\LaravelDeployer\Actions\Health\CheckResourcesAction;
use Shaf\LaravelDeployer\Actions\Deployment\SetupAction;
use Shaf\LaravelDeployer\Actions\Deployment\CheckLockAction;
use Shaf\LaravelDeployer\Actions\Deployment\LockAction;
use Shaf\LaravelDeployer\Actions\Deployment\ReleaseAction;
use Shaf\LaravelDeployer\Actions\Deployment\BuildAssetsAction;
use Shaf\LaravelDeployer\Actions\Deployment\RsyncAction;
use Shaf\LaravelDeployer\Actions\Deployment\SharedAction;
use Shaf\LaravelDeployer\Actions\Deployment\WritableAction;
use Shaf\LaravelDeployer\Actions\Deployment\VendorsAction;
use Shaf\LaravelDeployer\Actions\Deployment\FixModulePermissionsAction;
use Shaf\LaravelDeployer\Actions\Artisan\StorageLinkAction;
use Shaf\LaravelDeployer\Actions\Artisan\ConfigCacheAction;
use Shaf\LaravelDeployer\Actions\Artisan\ViewCacheAction;
use Shaf\LaravelDeployer\Actions\Artisan\RouteCacheAction;
use Shaf\LaravelDeployer\Actions\Artisan\OptimizeAction;
use Shaf\LaravelDeployer\Actions\Artisan\MigrateAction;
use Shaf\LaravelDeployer\Actions\Artisan\QueueRestartAction;
use Shaf\LaravelDeployer\Actions\System\RestartPhpFpmAction;
use Shaf\LaravelDeployer\Actions\System\RestartNginxAction;
use Shaf\LaravelDeployer\Actions\System\ReloadSupervisorAction;
use Shaf\LaravelDeployer\Actions\Deployment\SymlinkAction;
use Shaf\LaravelDeployer\Actions\Deployment\CleanupAction;
use Shaf\LaravelDeployer\Actions\Deployment\SuccessAction;
use Shaf\LaravelDeployer\Actions\Deployment\PostDeploymentAction;
use Shaf\LaravelDeployer\Actions\Health\CheckEndpointsAction;
use Shaf\LaravelDeployer\Actions\Deployment\LinkDepAction;
use Shaf\LaravelDeployer\Actions\Notification\NotifySuccessAction;
use Shaf\LaravelDeployer\Actions\Notification\NotifyFailureAction;
use Shaf\LaravelDeployer\Actions\Deployment\UnlockAction;
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

            // Send failure notification and unlock
            $this->deployer->execute([
                NotifyFailureAction::class,
                UnlockAction::class,
            ]);

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

        // Execute deployment actions in order
        $this->deployer->execute([
            DeployInfoAction::class,
            CheckResourcesAction::class,
            SetupAction::class,
            CheckLockAction::class,
            LockAction::class,
            ReleaseAction::class,
            BuildAssetsAction::class,
            RsyncAction::class,
            SharedAction::class,
            WritableAction::class,
            VendorsAction::class,
            FixModulePermissionsAction::class,
            StorageLinkAction::class,
            ConfigCacheAction::class,
            ViewCacheAction::class,
            RouteCacheAction::class,
            OptimizeAction::class,
            MigrateAction::class,
            QueueRestartAction::class,
            RestartPhpFpmAction::class,
            RestartNginxAction::class,
            ReloadSupervisorAction::class,
            SymlinkAction::class,
            CleanupAction::class,
            SuccessAction::class,
            PostDeploymentAction::class,
            CheckEndpointsAction::class,
            LinkDepAction::class,
            NotifySuccessAction::class,
            UnlockAction::class,
        ]);
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
