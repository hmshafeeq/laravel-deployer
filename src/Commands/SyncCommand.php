<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Actions\DiffAction;
use Shaf\LaravelDeployer\Actions\NotificationAction;
use Shaf\LaravelDeployer\Actions\OptimizeAction;
use Shaf\LaravelDeployer\Actions\SyncAction;
use Shaf\LaravelDeployer\Data\SyncFileCategories;
use Shaf\LaravelDeployer\Data\SyncStrategy;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;
use Shaf\LaravelDeployer\Services\DeploymentService;
use Shaf\LaravelDeployer\Services\GitDiffService;
use Shaf\LaravelDeployer\Services\RsyncService;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;

class SyncCommand extends Command
{
    protected $signature = 'deployer:sync {environment=staging : The deployment environment}
                            {--no-confirm : Skip sync confirmation}
                            {--skip-preview : Skip early diff preview}
                            {--dry-run : Show sync plan without executing}
                            {--dirty : Sync only uncommitted changes (git status)}
                            {--since= : Sync files changed since a commit}';

    protected $description = 'Sync files to an existing release without creating a new one';

    protected function configure(): void
    {
        parent::configure();

        // VALUE_OPTIONAL: --branch → current branch, --branch=dev → "dev", not passed → false
        $this->addOption(
            'branch',
            null,
            InputOption::VALUE_OPTIONAL,
            'Sync files changed compared to a branch (default: current branch)',
            false,
        );
    }

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $noConfirm = $this->option('no-confirm');
        $skipPreview = $this->option('skip-preview');
        $dryRun = $this->option('dry-run');

        // Determine sync strategy
        $strategy = $this->resolveStrategy();
        $reference = $this->resolveReference($strategy);

        // Fall back to full rsync if branch couldn't be resolved
        if ($strategy === SyncStrategy::Branch && $reference === null) {
            $this->warn('Could not detect current branch. Falling back to full rsync scan.');
            $strategy = SyncStrategy::Full;
        }

        // Validate environment
        $validEnvironments = ['local', 'staging', 'production'];
        if (! in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: '.implode(', ', $validEnvironments));

            return self::FAILURE;
        }

        // Check if Vite is running
        $skipBuildFolder = false;
        if (! $dryRun && $this->isViteRunning()) {
            if (! $this->components->confirm('Vite is running. Skip public/build & public/hot and continue?', true)) {
                return self::FAILURE;
            }
            $skipBuildFolder = true;
        }

        $filesFromPath = null;

        try {
            // Load configuration
            $config = ConfigService::load($environment, base_path(), $this->output);

            // Add Vite dev files to excludes if skipping
            if ($skipBuildFolder) {
                $config = $config->with([
                    'rsyncExcludes' => array_merge($config->rsyncExcludes, ['public/build/', 'public/hot']),
                ]);
            }

            // Initialize services
            $cmdService = new CommandService($config, $this->output);
            $deployService = new DeploymentService($config, $cmdService, base_path());
            $rsyncService = new RsyncService($config, base_path(), $cmdService);

            // Get current release
            $currentRelease = $deployService->getCurrentRelease();
            if (! $currentRelease) {
                $this->error('No current release found. Run a full deployment first.');
                $this->info("Run: php artisan deployer:release {$environment}");

                return self::FAILURE;
            }

            $releasePath = $deployService->getReleasePath($currentRelease);

            // Resolve git-based file list
            $changedFiles = [];
            $categories = null;

            if ($strategy->isGitBased()) {
                $gitDiff = new GitDiffService(base_path());

                // Validate reference
                if ($strategy === SyncStrategy::Since && ! $gitDiff->isValidCommit($reference)) {
                    $this->error("Invalid commit reference: {$reference}");

                    return self::FAILURE;
                }

                if ($strategy === SyncStrategy::Branch && ! $gitDiff->isValidBranch($reference)) {
                    $this->error("Invalid branch: {$reference}");

                    return self::FAILURE;
                }

                $changedFiles = $gitDiff->getChangedFiles($strategy, $reference);

                if (empty($changedFiles)) {
                    $this->newLine();
                    $this->info('No changed files found. Nothing to sync.');
                    $this->newLine();

                    return self::SUCCESS;
                }

                $categories = $gitDiff->categorizeFiles($changedFiles, $strategy);
                $filesFromPath = $gitDiff->writeFilesFromList($changedFiles);
            }

            // Handle dry-run mode
            if ($dryRun) {
                return $this->showDryRunPlan($config, $strategy, $currentRelease, $changedFiles, $categories, $filesFromPath);
            }

            // Show sync banner
            $this->showSyncBanner($config, $strategy, $currentRelease, $releasePath, $reference, $changedFiles);

            // Show diff preview
            if (! $skipPreview && $config->showPreview) {
                $diffAction = new DiffAction($cmdService, $config, base_path());
                $currentPath = "{$config->deployPath}/current";
                $diffAction->showRemoteDiff($currentPath);
            }

            // Confirm sync
            if (! $noConfirm) {
                if (! $this->confirm("Sync files to existing release {$currentRelease}?", false)) {
                    $this->newLine();
                    $this->comment('Sync cancelled');
                    $this->newLine();

                    return self::FAILURE;
                }
            }

            // Execute sync
            $diffAction = new DiffAction($cmdService, $config, base_path());
            $sync = new SyncAction($deployService, $cmdService, $rsyncService, $diffAction, $config);

            $sync->execute(
                releaseName: $currentRelease,
                releasePath: $releasePath,
                skipAssetBuild: $skipBuildFolder,
                filesFromPath: $filesFromPath,
                categories: $categories,
            );

            // Post-deployment optimization
            $this->newLine();
            $optimize = new OptimizeAction($cmdService, $config);

            if (! empty($config->postDeployCommands)) {
                $optimize->setPostDeployCommands($config->postDeployCommands);
            }

            $optimize->execute();

            // Send success notification
            $notify = new NotificationAction($config);
            $notify->success([
                'environment' => $config->environment->value,
                'release' => $currentRelease,
                'sync_only' => true,
                'strategy' => $strategy->value,
            ]);

            // Show summary
            $sync->showSummary();

            // Show skipped steps
            $skipped = $sync->getSkippedSteps();
            if (! empty($skipped)) {
                $this->newLine();
                $this->line('<fg=gray>Skipped steps: '.implode(', ', $skipped).'</>');
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('Sync failed!');
            $this->error($e->getMessage());
            $this->newLine();

            return self::FAILURE;
        } finally {
            // Clean up temp file
            if ($filesFromPath !== null && file_exists($filesFromPath)) {
                unlink($filesFromPath);
            }
        }
    }

    private function resolveStrategy(): SyncStrategy
    {
        if ($this->option('dirty')) {
            return SyncStrategy::Dirty;
        }

        if ($this->option('since') !== null) {
            return SyncStrategy::Since;
        }

        if ($this->option('branch') !== false) {
            return SyncStrategy::Branch;
        }

        return SyncStrategy::Full;
    }

    private function resolveReference(SyncStrategy $strategy): ?string
    {
        if ($strategy === SyncStrategy::Since) {
            return $this->option('since');
        }

        if ($strategy === SyncStrategy::Branch) {
            $branch = $this->option('branch');

            // --branch (no value) → detect current branch
            if (! $branch) {
                $branch = trim((string) shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --abbrev-ref HEAD 2>/dev/null'));
            }

            return $branch ?: null;
        }

        return null;
    }

    private function showSyncBanner($config, SyncStrategy $strategy, string $currentRelease, string $releasePath, ?string $reference, array $changedFiles): void
    {
        $this->newLine();
        $this->line('<fg=yellow>═══════════════════════════════════════════════════════════</>');
        $this->line('<fg=yellow>                    SYNC MODE</>');
        $this->line('<fg=yellow>═══════════════════════════════════════════════════════════</>');
        $this->newLine();
        $this->line('  <fg=cyan>Environment:</> '.$config->environment->value);
        $this->line('  <fg=cyan>Release:</> <fg=white>'.$currentRelease.'</>');
        $this->line('  <fg=cyan>Strategy:</> '.$strategy->getLabel());

        if ($reference !== null) {
            $this->line("  <fg=cyan>Reference:</> {$reference}");
        }

        if ($strategy->isGitBased() && ! empty($changedFiles)) {
            $this->line('  <fg=cyan>Files:</> '.count($changedFiles).' changed');
        }

        $this->newLine();
        $this->line('<fg=yellow>═══════════════════════════════════════════════════════════</>');
        $this->newLine();
    }

    private function showDryRunPlan($config, SyncStrategy $strategy, string $currentRelease, array $changedFiles, ?SyncFileCategories $categories, ?string $filesFromPath): int
    {
        $this->newLine();
        $this->line('<fg=cyan>╔══════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=cyan>║</>         <fg=white;options=bold>DRY RUN - Sync Plan (no changes)</>           <fg=cyan>║</>');
        $this->line('<fg=cyan>╠══════════════════════════════════════════════════════════╣</>');
        $this->newLine();

        $this->line("  <fg=white>Environment:</>  <fg=yellow>{$config->environment->value}</>");
        $this->line("  <fg=white>Release:</>      <fg=yellow>{$currentRelease}</>");
        $this->line("  <fg=white>Strategy:</>     <fg=yellow>{$strategy->getLabel()}</>");

        if ($strategy->isGitBased()) {
            $this->line('  <fg=white>Files:</>        <fg=yellow>'.count($changedFiles).' changed</>');
            $this->newLine();

            // Show file list (max 20 files)
            $shown = array_slice($changedFiles, 0, 20);
            foreach ($shown as $file) {
                $this->line("    <fg=green>→</> {$file}");
            }

            if (count($changedFiles) > 20) {
                $remaining = count($changedFiles) - 20;
                $this->line("    <fg=gray>... and {$remaining} more files</>");
            }

            // Show smart skip analysis
            if ($categories !== null) {
                $this->newLine();
                $this->line('  <fg=white>Smart Step Skipping:</>');

                $steps = [
                    ['assets:build', $categories->hasFrontendAssets],
                    ['composer:install', $categories->hasComposerLock],
                    ['permissions:fix', $categories->hasNewFiles],
                    ['artisan:migrate', $categories->hasMigrations],
                ];

                foreach ($steps as [$step, $willRun]) {
                    $icon = $willRun ? '<fg=green>RUN</>' : '<fg=gray>SKIP</>';
                    $this->line("    {$icon}  {$step}");
                }
            }
        }

        $this->newLine();
        $this->line('<fg=cyan>╚══════════════════════════════════════════════════════════╝</>');
        $this->newLine();

        $this->info('Run without --dry-run to execute the sync.');
        $this->newLine();

        // Clean up temp file
        if ($filesFromPath !== null && file_exists($filesFromPath)) {
            unlink($filesFromPath);
        }

        return self::SUCCESS;
    }

    protected function isViteRunning(): bool
    {
        $process = new Process(['ps', 'aux']);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        $output = $process->getOutput();
        $rootVitePath = base_path('node_modules/.bin/vite');

        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, $rootVitePath)) {
                return true;
            }
        }

        return false;
    }
}
