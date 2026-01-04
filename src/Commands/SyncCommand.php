<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Actions\DiffAction;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;
use Shaf\LaravelDeployer\Services\RsyncService;

/**
 * Quick sync command for syncing files without full deployment.
 *
 * This command syncs local files to the current release on the server
 * without creating a new release or running any deployment hooks.
 * Useful for quick hotfixes or config changes.
 */
class SyncCommand extends Command
{
    protected $signature = 'deploy:sync {environment=staging : The deployment environment}
                            {--no-confirm : Skip sync confirmation}
                            {--dry-run : Show changes without syncing}';

    protected $description = 'Sync local files to server without full deployment (hot-sync to current release)';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $noConfirm = $this->option('no-confirm');
        $dryRun = $this->option('dry-run');

        // Validate environment
        $validEnvironments = ['local', 'staging', 'production'];
        if (! in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: '.implode(', ', $validEnvironments));

            return self::FAILURE;
        }

        try {
            // Load configuration
            $config = ConfigService::load($environment, base_path(), $this->output);

            // Initialize services
            $cmdService = new CommandService($config, $this->output);
            $rsyncService = new RsyncService($config, base_path(), $cmdService);
            $diffAction = new DiffAction($cmdService, $config, base_path());

            // Determine destination - sync to current release
            $destination = "{$config->deployPath}/current";

            // For remote deployments, verify current symlink exists before doing anything
            if (! $config->isLocal) {
                $cmdService->info('Verifying server state...');
                $exists = trim($cmdService->remote("test -L {$config->deployPath}/current && echo 'yes' || echo 'no'"));

                if ($exists !== 'yes') {
                    $this->error('No current release found on server.');
                    $this->info('Run a full deployment first: php artisan deploy '.$environment);

                    return self::FAILURE;
                }
            }

            // Show header
            $this->showHeader($config);

            // Calculate and show diff against remote
            $this->newLine();
            $this->info('Calculating file differences against remote server...');
            $this->newLine();

            $diff = $diffAction->showRemoteDiff($destination);

            // Handle empty diff
            if ($diff->isEmpty()) {
                $this->newLine();
                $this->info('Nothing to sync - server is already up to date!');
                $this->newLine();

                return self::SUCCESS;
            }

            // Dry-run mode - just show diff and exit
            if ($dryRun) {
                $this->newLine();
                $this->comment('Dry-run mode - no files were synced.');
                $this->newLine();

                return self::SUCCESS;
            }

            // Production warning
            if ($config->environment->isProduction()) {
                $this->newLine();
                $this->components->warn('⚠️  WARNING: You are syncing directly to PRODUCTION!');
                $this->line('   This will modify the live site immediately.');
                $this->newLine();
            }

            // Confirmation
            if (! $noConfirm) {
                if (! $this->confirm('Do you want to sync these files to the server?', false)) {
                    $this->newLine();
                    $this->comment('Sync cancelled.');
                    $this->newLine();

                    return self::SUCCESS;
                }
            }

            // Execute sync
            $this->newLine();
            $cmdService->section('SYNCING FILES');

            $rsyncService
                ->setSyncDiff($diff)
                ->setOutput($this->output);

            $rsyncService->sync($destination);

            // Clear caches after sync (optional but recommended)
            $this->newLine();
            if ($this->confirm('Clear application caches?', true)) {
                $cmdService->info('Clearing caches...');

                $currentPath = "{$config->deployPath}/current";
                $cmdService->remote("cd {$currentPath} && {$config->phpBinary} artisan config:clear 2>/dev/null || true");
                $cmdService->remote("cd {$currentPath} && {$config->phpBinary} artisan route:clear 2>/dev/null || true");
                $cmdService->remote("cd {$currentPath} && {$config->phpBinary} artisan view:clear 2>/dev/null || true");

                $cmdService->success('Caches cleared');
            }

            // Summary
            $this->newLine();
            $this->components->info("✓ Synced {$diff->totalCount()} file(s) to {$environment}");
            $this->newLine();

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('Sync failed!');
            $this->error($e->getMessage());
            $this->newLine();

            return self::FAILURE;
        }
    }

    /**
     * Show sync header
     */
    private function showHeader($config): void
    {
        $environment = $config->environment->value;
        $hostname = $config->hostname;
        $deployPath = $config->deployPath;

        $this->newLine();
        $this->line('<fg=cyan>═══════════════════════════════════════════════════════════</>');
        $this->line('<fg=cyan>                    QUICK FILE SYNC</>');
        $this->line('<fg=cyan>═══════════════════════════════════════════════════════════</>');
        $this->newLine();
        $this->line("  <info>Environment:</info>  <fg=yellow>{$environment}</>");
        $this->line("  <info>Server:</info>       <fg=yellow>{$hostname}</>");
        $this->line("  <info>Target:</info>       <fg=yellow>{$deployPath}/current</>");
        $this->newLine();
        $this->line('<fg=gray>  This syncs files directly to the current release.</>');
        $this->line('<fg=gray>  No new release will be created.</>');
        $this->line('<fg=cyan>═══════════════════════════════════════════════════════════</>');
    }
}
