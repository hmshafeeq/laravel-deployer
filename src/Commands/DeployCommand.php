<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Actions\DeployAction;
use Shaf\LaravelDeployer\Actions\DiffAction;
use Shaf\LaravelDeployer\Actions\HealthCheckAction;
use Shaf\LaravelDeployer\Actions\NotificationAction;
use Shaf\LaravelDeployer\Actions\OptimizeAction;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;
use Shaf\LaravelDeployer\Services\DeploymentService;
use Shaf\LaravelDeployer\Services\RsyncService;
use Symfony\Component\Process\Process;

class DeployCommand extends Command
{
    protected $signature = 'deploy {environment=staging : The deployment environment (local, staging, production)}
                            {--no-confirm : Skip deployment confirmation}
                            {--skip-health-check : Skip health check before deployment}
                            {--dry-run : Show deployment plan without executing}';

    protected $description = 'Deploy the application using simplified action-based deployment';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $noConfirm = $this->option('no-confirm');
        $skipHealthCheck = $this->option('skip-health-check');
        $dryRun = $this->option('dry-run');

        // Validate environment
        $validEnvironments = ['local', 'staging', 'production'];
        if (! in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: '.implode(', ', $validEnvironments));

            return self::FAILURE;
        }

        // Check if Vite is running (skip in dry-run mode)
        if (! $dryRun && $this->isViteRunning()) {
            $this->newLine();
            $this->components->error('Vite bundler is currently running!');
            $this->newLine();
            $this->components->warn('Please stop the Vite development server before deploying. 💡 Press Ctrl+C in the terminal where Vite is running to stop it.');
            $this->newLine();

            return self::FAILURE;
        }

        try {
            // Load configuration
            $config = ConfigService::load($environment, base_path(), $this->output);

            // Handle dry-run mode
            if ($dryRun) {
                return $this->showDryRunPlan($config);
            }

            // SAFETY WARNING: Local deployments can be dangerous
            if ($config->isLocal) {
                $this->newLine();
                $this->components->warn('⚠️  LOCAL DEPLOYMENT MODE DETECTED');
                $this->line('   Local mode executes rsync with --delete on local paths.');
                $this->line('   This can DELETE files on your local machine!');
                $this->newLine();

                if (! $this->components->confirm('Are you SURE you want to deploy in local mode?', false)) {
                    $this->newLine();
                    $this->comment('🛑 Local deployment cancelled for safety');
                    $this->newLine();

                    return self::FAILURE;
                }
            }

            // Initialize services
            $cmdService = new CommandService($config, $this->output);
            $deployService = new DeploymentService($config, base_path());
            $rsyncService = new RsyncService($config, base_path(), $cmdService);
            $diffAction = new DiffAction($cmdService, $config, base_path());

            // Show deployment confirmation
            if (! $noConfirm && ! $this->confirmDeployment($config)) {
                $this->newLine();
                $this->comment('🛑 Deployment cancelled by user');
                $this->newLine();

                return self::FAILURE;
            }

            // Health check (optional)
            if (! $skipHealthCheck) {
                $healthCheck = new HealthCheckAction($cmdService, $config);
                if (! $healthCheck->check()) {
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
                $config = $config ?? ConfigService::load($environment, base_path(), $this->output);
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

        if (! $process->isSuccessful()) {
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

    /**
     * Show dry-run deployment plan without executing
     */
    private function showDryRunPlan(DeploymentConfig $config): int
    {
        $this->newLine();
        $this->line('<fg=cyan>╔══════════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=cyan>║                    DRY RUN - No changes made                  ║</>');
        $this->line('<fg=cyan>╠══════════════════════════════════════════════════════════════╣</>');

        // Environment info
        $this->line('<fg=cyan>║</> <fg=white>Environment:</> <fg=yellow>'.$this->padRight($config->environment->value, 44).'<fg=cyan>║</>');
        $this->line('<fg=cyan>║</> <fg=white>Server:</>      <fg=yellow>'.$this->padRight($config->hostname, 44).'<fg=cyan>║</>');
        $this->line('<fg=cyan>║</> <fg=white>Deploy Path:</> <fg=yellow>'.$this->padRight($config->deployPath, 44).'<fg=cyan>║</>');

        $this->line('<fg=cyan>╠══════════════════════════════════════════════════════════════╣</>');
        $this->line('<fg=cyan>║</>                   <fg=white>Deployment Steps</>                          <fg=cyan>║</>');
        $this->line('<fg=cyan>╠══════════════════════════════════════════════════════════════╣</>');

        // Show steps
        $steps = [
            ['1', 'Lock deployment', 'Prevent concurrent deployments'],
            ['2', 'Create release directory', $this->generateReleaseName()],
            ['3', 'Build frontend assets', 'npm run build'],
            ['4', 'Calculate file diff', 'Compare local → server'],
            ['5', 'Sync files via rsync', 'Upload changed files'],
            ['6', 'Link shared directories', 'storage, .env'],
            ['7', 'Install Composer deps', 'composer install'],
            ['8', 'Set permissions', 'chmod 755 dirs, 644 files'],
            ['9', 'Run migrations', 'php artisan migrate --force'],
            ['10', 'Symlink release', 'current → new release'],
        ];

        if ($config->healthCheckEnabled && $config->healthCheckUrl) {
            $steps[] = ['11', 'Health check', "GET {$config->healthCheckUrl}"];
        }

        $steps[] = [count($steps) + 1, 'Cleanup old releases', "Keep {$config->keepReleases} releases"];

        if (! empty($config->postDeployCommands)) {
            $commands = implode(', ', array_slice($config->postDeployCommands, 0, 3));
            if (count($config->postDeployCommands) > 3) {
                $commands .= '...';
            }
            $steps[] = [count($steps) + 1, 'Post-deploy commands', $commands];
        }

        foreach ($steps as $step) {
            $stepNum = str_pad($step[0], 2, ' ', STR_PAD_LEFT);
            $action = $this->padRight($step[1], 22);
            $detail = $this->padRight($step[2], 30);
            $this->line("<fg=cyan>║</> <fg=green>{$stepNum}.</> {$action} <fg=gray>{$detail}</><fg=cyan>║</>");
        }

        $this->line('<fg=cyan>╠══════════════════════════════════════════════════════════════╣</>');

        // Show file diff preview
        $this->line('<fg=cyan>║</>                    <fg=white>Files to Deploy</>                          <fg=cyan>║</>');
        $this->line('<fg=cyan>╠══════════════════════════════════════════════════════════════╣</>');

        // Calculate actual diff
        $cmdService = new CommandService($config, $this->output);
        $diffAction = new DiffAction($cmdService, $config, base_path());

        try {
            $diff = $diffAction->show();

            if ($diff->isEmpty()) {
                $this->line('<fg=cyan>║</>   <fg=gray>No file changes detected</>                               <fg=cyan>║</>');
            } else {
                if ($diff->hasNew()) {
                    $this->line('<fg=cyan>║</>   <fg=green>+ '.$this->padRight($diff->newCount().' new files', 54).'</><fg=cyan>║</>');
                }
                if ($diff->hasModified()) {
                    $this->line('<fg=cyan>║</>   <fg=yellow>~ '.$this->padRight($diff->modifiedCount().' modified files', 54).'</><fg=cyan>║</>');
                }
                if ($diff->hasDeleted()) {
                    $this->line('<fg=cyan>║</>   <fg=red>- '.$this->padRight($diff->deletedCount().' deleted files', 54).'</><fg=cyan>║</>');
                }
            }
        } catch (\Exception $e) {
            $this->line('<fg=cyan>║</>   <fg=gray>Unable to calculate diff: '.$this->padRight(substr($e->getMessage(), 0, 30), 30).'</><fg=cyan>║</>');
        }

        $this->line('<fg=cyan>╚══════════════════════════════════════════════════════════════╝</>');
        $this->newLine();

        $this->info('Run without --dry-run to execute the deployment.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Generate a release name for preview
     */
    private function generateReleaseName(): string
    {
        $yearMonth = date('Ym');

        return "{$yearMonth}.X (auto-generated)";
    }

    /**
     * Pad string to fixed width for table formatting
     */
    private function padRight(string $str, int $length): string
    {
        $str = substr($str, 0, $length);

        return str_pad($str, $length);
    }
}
