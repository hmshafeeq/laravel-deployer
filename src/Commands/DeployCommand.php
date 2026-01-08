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
use Shaf\LaravelDeployer\Services\ReceiptService;
use Shaf\LaravelDeployer\Services\RsyncService;
use Shaf\LaravelDeployer\Support\InteractiveMode;
use Symfony\Component\Process\Process;

class DeployCommand extends Command
{
    protected $signature = 'deployer {environment=staging : The deployment environment (local, staging, production)}
                            {--no-confirm : Skip deployment confirmation}
                            {--skip-health-check : Skip health check before deployment}
                            {--skip-preview : Skip early diff preview}
                            {--dry-run : Show deployment plan without executing}
                            {--interactive : Interactive mode - prompt for each deployment option}';

    protected $description = 'Deploy the application using simplified action-based deployment';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $noConfirm = $this->option('no-confirm');
        $skipHealthCheck = $this->option('skip-health-check');
        $skipPreview = $this->option('skip-preview');
        $dryRun = $this->option('dry-run');
        $interactive = $this->option('interactive');

        // Validate environment
        $validEnvironments = ['local', 'staging', 'production'];
        if (! in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: '.implode(', ', $validEnvironments));

            return self::FAILURE;
        }

        // Check if Vite is running (skip in dry-run mode)
        $skipBuildFolder = false;
        if (! $dryRun && $this->isViteRunning()) {
            if (! $this->components->confirm('Vite is running. Skip public/build & public/hot and continue?', true)) {
                return self::FAILURE;
            }
            $skipBuildFolder = true;
        }

        try {
            // Load configuration
            $config = ConfigService::load($environment, base_path(), $this->output);

            // Add Vite dev files to excludes if skipping
            if ($skipBuildFolder) {
                $config = $this->addViteDevExcludes($config);
            }

            // Handle dry-run mode
            if ($dryRun) {
                return $this->showDryRunPlan($config);
            }

            // Interactive mode options
            $interactiveOptions = null;
            if ($interactive) {
                $interactiveMode = new InteractiveMode($this->input, $this->output, $config);
                $interactiveOptions = $interactiveMode->prompt();

                // Override config based on interactive options
                $config = $this->applyInteractiveOptions($config, $interactiveOptions);
                $noConfirm = ! $interactiveOptions['confirmChanges'];
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
            $deployService = new DeploymentService($config, $cmdService, base_path());
            $rsyncService = new RsyncService($config, base_path(), $cmdService);
            $diffAction = new DiffAction($cmdService, $config, base_path());

            // Show early diff preview (compare local vs current release on server)
            $previewShown = false;
            if (! $skipPreview && $config->showPreview) {
                $this->showEarlyDiffPreview($config, $cmdService, $diffAction);
                $previewShown = true;
            }

            // If preview was shown, disable showDiff during deployment to avoid duplicate
            if ($previewShown && $config->showDiff) {
                $config = $this->disableShowDiff($config);
            }

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

            // Initialize health check action for post-deployment verification
            $postHealthCheck = null;
            if ($config->isHealthCheckEnabled()) {
                $postHealthCheck = new HealthCheckAction($cmdService, $config);
            }

            // Initialize receipt service
            $receiptService = new ReceiptService($cmdService, $config);

            // Extract postDeploy commands - these will be run by OptimizeAction AFTER service restart
            // to ensure they execute with fresh OPcache (e.g., view:clear, filament:optimize)
            $postDeployCommands = $config->postDeployCommands;
            $runOptimization = ! $interactive || ($interactiveOptions['optimizeApp'] ?? true);

            // Create config without postDeploy commands for DeployAction
            // (OptimizeAction will run them after service restart for fresh OPcache)
            $deployConfig = $runOptimization && ! empty($postDeployCommands)
                ? $config->with(['postDeployCommands' => []])
                : $config;

            // Execute deployment
            $deploy = new DeployAction(
                $deployService,
                $cmdService,
                $rsyncService,
                $diffAction,
                $deployConfig,
                $postHealthCheck,
                $receiptService
            );
            $deploy->execute();

            // Post-deployment optimization (skip if interactive mode disabled it)
            if ($runOptimization) {
                $this->newLine();
                $optimize = new OptimizeAction($cmdService, $config);

                // Pass postDeploy commands to run AFTER service restart (fresh OPcache)
                if (! empty($postDeployCommands)) {
                    $optimize->setPostDeployCommands($postDeployCommands);
                }

                $optimize->execute();
            }

            // Send success notification
            $notify = new NotificationAction($config);
            $notify->success([
                'environment' => $config->environment->value,
                'release' => $deploy->getReleaseName(),
            ]);

            // Show deployment summary dashboard at the very end
            $deploy->showSummary();

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
     * Apply interactive mode options to config
     */
    private function applyInteractiveOptions(DeploymentConfig $config, array $options): DeploymentConfig
    {
        return $config->with([
            'showDiff' => $options['showDiff'] ?? $config->showDiff,
            'showPreview' => $options['showPreview'] ?? $config->showPreview,
            'confirmChanges' => $options['confirmChanges'] ?? $config->confirmChanges,
        ]);
    }

    /**
     * Disable showDiff in config (to avoid duplicate diff display)
     */
    private function disableShowDiff(DeploymentConfig $config): DeploymentConfig
    {
        return $config->with(['showDiff' => false]);
    }

    /**
     * Add Vite dev files to rsync excludes (public/build/ and public/hot)
     */
    private function addViteDevExcludes(DeploymentConfig $config): DeploymentConfig
    {
        return $config->with([
            'rsyncExcludes' => array_merge($config->rsyncExcludes, ['public/build/', 'public/hot']),
        ]);
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
     * Show early diff preview before confirmation
     */
    private function showEarlyDiffPreview(
        DeploymentConfig $config,
        CommandService $cmdService,
        DiffAction $diffAction
    ): void {
        $currentPath = "{$config->deployPath}/current";

        // Check if current symlink exists on server
        if ($config->isLocal) {
            $exists = is_link($currentPath);
        } else {
            $exists = trim($cmdService->remote("test -L {$currentPath} && echo 'yes' || echo 'no'")) === 'yes';
        }

        if (! $exists) {
            $this->newLine();
            $cmdService->info('  First deployment - no existing release to compare');
            $this->newLine();

            return;
        }

        // Show diff against current release
        $this->newLine();
        $diffAction->showRemoteDiff($currentPath);
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
        // Box width = 64 characters (including borders)
        // Inner content = 62 characters
        $boxWidth = 64;
        $innerWidth = $boxWidth - 2; // 62

        $this->newLine();
        $this->line('<fg=cyan>╔'.str_repeat('═', $innerWidth).'╗</>');
        $this->line('<fg=cyan>║'.$this->centerText('DRY RUN - No changes made', $innerWidth).'║</>');
        $this->line('<fg=cyan>╠'.str_repeat('═', $innerWidth).'╣</>');

        // Environment info - label width 14, value gets the rest
        $labelWidth = 14;
        $valueWidth = $innerWidth - $labelWidth - 2; // 46

        $this->line('<fg=cyan>║</> '.$this->formatTableRow('Environment:', $config->environment->value, $labelWidth, $valueWidth).' <fg=cyan>║</>');
        $this->line('<fg=cyan>║</> '.$this->formatTableRow('Server:', $config->hostname, $labelWidth, $valueWidth).' <fg=cyan>║</>');
        $this->line('<fg=cyan>║</> '.$this->formatTableRow('Deploy Path:', $config->deployPath, $labelWidth, $valueWidth).' <fg=cyan>║</>');

        $this->line('<fg=cyan>╠'.str_repeat('═', $innerWidth).'╣</>');
        $this->line('<fg=cyan>║'.$this->centerText('Deployment Steps', $innerWidth).'║</>');
        $this->line('<fg=cyan>╠'.str_repeat('═', $innerWidth).'╣</>');

        // Show steps - step num (4), action (24), detail (30) = 58 + spacing
        $steps = [
            ['1', 'Lock deployment', 'Prevent concurrent deploys'],
            ['2', 'Create release directory', $this->generateReleaseName()],
            ['3', 'Build frontend assets', 'npm run build'],
            ['4', 'Calculate file diff', 'Compare local → server'],
            ['5', 'Sync files via rsync', 'Upload changed files'],
            ['6', 'Link shared directories', 'storage, .env'],
            ['7', 'Install Composer deps', 'composer install'],
            ['8', 'Set permissions', 'chmod 755/644'],
            ['9', 'Run migrations', 'artisan migrate --force'],
            ['10', 'Symlink release', 'current → new release'],
        ];

        if ($config->isHealthCheckEnabled()) {
            $steps[] = [(string) (count($steps) + 1), 'Health check', 'GET '.$config->healthCheckUrl];
        }

        $steps[] = [(string) (count($steps) + 1), 'Cleanup old releases', "Keep {$config->keepReleases} releases"];

        if (! empty($config->postDeployCommands)) {
            $commands = implode(', ', array_slice($config->postDeployCommands, 0, 2));
            if (count($config->postDeployCommands) > 2) {
                $commands .= '...';
            }
            $steps[] = [(string) (count($steps) + 1), 'Post-deploy commands', $commands];
        }

        foreach ($steps as $step) {
            $stepNum = str_pad($step[0].'.', 4, ' ', STR_PAD_LEFT);
            $action = $this->padRight($step[1], 24);
            $detail = $this->padRight($step[2], 28);
            $this->line("<fg=cyan>║</> <fg=green>{$stepNum}</> {$action} <fg=gray>{$detail}</> <fg=cyan>║</>");
        }

        $this->line('<fg=cyan>╠'.str_repeat('═', $innerWidth).'╣</>');
        $this->line('<fg=cyan>║'.$this->centerText('Files to Deploy', $innerWidth).'║</>');
        $this->line('<fg=cyan>╠'.str_repeat('═', $innerWidth).'╣</>');

        // Calculate actual diff (silently - don't show the diff output)
        $cmdService = new CommandService($config, $this->output);
        $diffAction = new DiffAction($cmdService, $config, base_path());

        try {
            $diff = $diffAction->calculate(); // Use calculate() instead of show() to avoid output

            $contentWidth = $innerWidth - 4; // 58
            if ($diff->isEmpty()) {
                $this->line('<fg=cyan>║</>  <fg=gray>'.$this->padRight('No file changes detected', $contentWidth).'</> <fg=cyan>║</>');
            } else {
                if ($diff->hasNew()) {
                    $this->line('<fg=cyan>║</>  <fg=green>+ '.$this->padRight($diff->newCount().' new files', $contentWidth - 2).'</> <fg=cyan>║</>');
                }
                if ($diff->hasModified()) {
                    $this->line('<fg=cyan>║</>  <fg=yellow>~ '.$this->padRight($diff->modifiedCount().' modified files', $contentWidth - 2).'</> <fg=cyan>║</>');
                }
                if ($diff->hasDeleted()) {
                    $this->line('<fg=cyan>║</>  <fg=red>- '.$this->padRight($diff->deletedCount().' deleted files', $contentWidth - 2).'</> <fg=cyan>║</>');
                }
            }
        } catch (\Exception $e) {
            $contentWidth = $innerWidth - 4;
            $errorMsg = 'Unable to calculate diff';
            $this->line('<fg=cyan>║</>  <fg=gray>'.$this->padRight($errorMsg, $contentWidth).'</> <fg=cyan>║</>');
        }

        $this->line('<fg=cyan>╚'.str_repeat('═', $innerWidth).'╝</>');
        $this->newLine();

        $this->info('Run without --dry-run to execute the deployment.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Format a table row with label and value
     */
    private function formatTableRow(string $label, string $value, int $labelWidth, int $valueWidth): string
    {
        $labelFormatted = str_pad($label, $labelWidth);
        $valueFormatted = $this->padRight($value, $valueWidth);

        return "<fg=white>{$labelFormatted}</><fg=yellow>{$valueFormatted}</>";
    }

    /**
     * Center text within a given width
     */
    private function centerText(string $text, int $width): string
    {
        $textLen = mb_strlen($text);
        if ($textLen >= $width) {
            return substr($text, 0, $width);
        }
        $padding = (int) (($width - $textLen) / 2);
        $rightPadding = $width - $textLen - $padding;

        return str_repeat(' ', $padding).$text.str_repeat(' ', $rightPadding);
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
