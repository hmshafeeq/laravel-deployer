<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Actions\DeployAction;
use Shaf\LaravelDeployer\Actions\DiffAction;
use Shaf\LaravelDeployer\Actions\HealthCheckAction;
use Shaf\LaravelDeployer\Actions\NotificationAction;
use Shaf\LaravelDeployer\Actions\OptimizeAction;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;
use Shaf\LaravelDeployer\Services\DeploymentService;
use Shaf\LaravelDeployer\Services\RsyncService;
use Symfony\Component\Process\Process;

class DeployCommand extends Command
{
    protected $signature = 'deploy {environment=staging : The deployment environment (local, staging, production)}
                            {--no-confirm : Skip deployment confirmation}
                            {--skip-health-check : Skip health check before deployment}';

    protected $description = 'Deploy the application using simplified action-based deployment';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $noConfirm = $this->option('no-confirm');
        $skipHealthCheck = $this->option('skip-health-check');

        // Validate environment
        $validEnvironments = ['local', 'staging', 'production'];

        if (!in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: ' . implode(', ', $validEnvironments));
            return self::FAILURE;
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
        $viteDetector = new ViteDetector();

        // Check if Vite is running
        if ($viteDetector->isRunning()) {
            $this->newLine();
            $this->components->error('Vite bundler is currently running!');
            $this->newLine();
            $this->components->warn('Please stop the Vite development server before deploying. 💡 Press Ctrl+C in the terminal where Vite is running to stop it.');
            $this->newLine();
            return self::FAILURE;
        }

        try {
            // Load configuration
            $config = ConfigService::load($environment, base_path());

            // Initialize services
            $cmdService = new CommandService($config, $this->output);
            $deployService = new DeploymentService($config, base_path());
            $rsyncService = new RsyncService($config, base_path(), $cmdService);
            $diffAction = new DiffAction($cmdService, $config, base_path());

            // Show deployment confirmation
            if (!$noConfirm && !$this->confirmDeployment($config)) {
                $this->newLine();
                $this->comment('🛑 Deployment cancelled by user');
                $this->newLine();
                return self::FAILURE;
            }

            // Health check (optional)
            if (!$skipHealthCheck) {
                $healthCheck = new HealthCheckAction($cmdService, $config);
                if (!$healthCheck->check()) {
                    $this->error('Health check failed!');
                    $this->newLine();
                    return self::FAILURE;
                }
                $this->newLine();
            }

            // Execute deployment
            $deploy = new DeployAction($deployService, $cmdService, $rsyncService, $diffAction, $config);
            $deploy->execute();

            // Post-deployment optimization
            $this->newLine();
            $optimize = new OptimizeAction($cmdService, $config);
            $optimize->execute();

            // Send success notification
            $notify = new NotificationAction($config);
            $notify->success([
                'environment' => $config->environment->value,
                'release' => $deploy->getReleaseName(),
            ]);

            $this->newLine();
            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('Deployment failed!');
            $this->error($e->getMessage());
            $this->newLine();

            // Send failure notification
            try {
                $config = $config ?? ConfigService::load($environment, base_path());
                $notify = new NotificationAction($config);
                $notify->failure($e);
            } catch (\Exception $notifyError) {
                // Silently fail if notification fails
            }

            return self::FAILURE;
        }
    }

    /**
     * Show deployment confirmation prompt
     */
    private function confirmDeployment($config): bool
    {
        $environment = $config->environment->value;
        $hostname = $config->hostname;
        $deployPath = $config->deployPath;
        $user = $config->remoteUser;

        $this->newLine();
        $this->line('<fg=yellow>═══════════════════════════════════════════════════════════</>');
        $this->line('<fg=yellow>                 DEPLOYMENT CONFIRMATION</>');
        $this->line('<fg=yellow>═══════════════════════════════════════════════════════════</>');
        $this->newLine();
        $this->line("  <info>Environment:</info>  <fg=cyan>{$environment}</>");
        $this->line("  <info>Server:</info>       <fg=cyan>{$hostname}</>");
        $this->line("  <info>User:</info>         <fg=cyan>{$user}</>");
        $this->line("  <info>Deploy Path:</info>  <fg=cyan>{$deployPath}</>");
        $this->newLine();

        // Extra warning for production
        if (strtolower($environment) === 'production' || strtolower($environment) === 'prod') {
            $this->line('<fg=red>⚠️  WARNING: You are deploying to PRODUCTION!</>');
            $this->newLine();
        }

        $this->line('<fg=yellow>═══════════════════════════════════════════════════════════</>');
        $this->newLine();

        return $this->confirm('  Do you want to continue with this deployment?', true);
    }

    /**
     * Check if Vite is running
     */
    protected function isViteRunning(): bool
    {
        $process = new Process(['ps', 'aux']);
        $process->run();

    /**
     * Run pre-deployment health checks
     *
     * @return void
     */
    protected function runHealthChecks(): void
    {
        $healthCheckService = new HealthCheckService($this->deployer);
        $healthCheckService->runPreDeployment();
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
        $serviceRestarter = new ServiceRestarter($this->deployer);
        $serviceRestarter->restartAll(failSilently: true);
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
        $healthCheckService = new HealthCheckService($this->deployer);
        $healthCheckService->runPostDeployment();
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
}
