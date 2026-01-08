<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Constants\Paths;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;
use Shaf\LaravelDeployer\Services\DeploymentService;
use Shaf\LaravelDeployer\Services\ReceiptService;

class DiagnoseCommand extends Command
{
    protected $signature = 'deployer:diagnose
                            {environment : The deployment environment (staging, production, etc.)}
                            {--path= : Custom base path for local comparison}
                            {--compare : Compare local vs remote files in asset directories}';

    protected $description = 'Diagnose deployment issues by inspecting server state';

    /** @var array<string, array{status: string, message: string}> */
    private array $issues = [];

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $compare = $this->option('compare');
        $customPath = $this->option('path');

        try {
            // Load configuration
            $config = ConfigService::load($environment, base_path(), $this->output);

            // Initialize services
            $cmd = new CommandService($config, $this->output);
            $deployment = new DeploymentService($config, $cmd, base_path());
            $receipt = new ReceiptService($cmd, $config);

            // Test connection first
            if (! $cmd->testConnection()) {
                $this->error("Failed to connect to {$environment} server.");

                return self::FAILURE;
            }

            $this->renderHeader($environment, $config->hostname);

            // Section 1: Release Information
            $this->renderReleaseInfo($cmd, $deployment, $receipt);

            // Section 2: Public Assets
            $this->renderPublicAssets($cmd, $deployment);

            // Section 3: Storage & Bootstrap
            $this->renderStorageDirectories($cmd, $deployment);

            // Section 4: Symlinks
            $this->renderSymlinks($cmd, $deployment);

            // Section 5: Key File Permissions
            $this->renderPermissions($cmd, $deployment);

            // Section 6: Compare local vs remote (if --compare flag)
            if ($compare) {
                $basePath = $customPath ?: base_path();
                $this->renderComparison($cmd, $deployment, $basePath);
            }

            // Section 7: Summary of Issues
            $this->renderIssuesSummary();

            return empty($this->issues) ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('Diagnostic failed!');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function renderHeader(string $environment, string $hostname): void
    {
        $this->newLine();
        $this->line('<fg=cyan>═══════════════════════════════════════════════════════════</>');
        $this->line("<fg=cyan>  DEPLOYMENT DIAGNOSTICS - {$environment}</>");
        $this->line("<fg=cyan>  Host: {$hostname}</>");
        $this->line('<fg=cyan>═══════════════════════════════════════════════════════════</>');
        $this->newLine();
    }

    private function renderReleaseInfo(CommandService $cmd, DeploymentService $deployment, ReceiptService $receipt): void
    {
        $this->info('Release Information');
        $this->line(str_repeat('-', 50));

        // Current release
        $currentRelease = $deployment->getCurrentRelease();
        if ($currentRelease) {
            $this->line("  Current Release: <fg=green>{$currentRelease}</>");
            $currentPath = $deployment->getReleasePath($currentRelease);
            $this->line("  Path: {$currentPath}");
        } else {
            $this->line('  Current Release: <fg=yellow>none</> (no symlink)');
            $this->addIssue('release', 'No current release symlink found');
        }

        // Available releases
        $releases = $deployment->getReleases();
        $this->line('  Available Releases: '.count($releases));

        if (count($releases) > 0) {
            $displayLimit = 5;
            $displayReleases = array_slice($releases, 0, $displayLimit);
            foreach ($displayReleases as $release) {
                $marker = ($release === $currentRelease) ? ' <fg=green>(current)</>' : '';
                $this->line("    - {$release}{$marker}");
            }
            if (count($releases) > $displayLimit) {
                $remaining = count($releases) - $displayLimit;
                $this->line("    <fg=gray>... and {$remaining} more</>");
            }
        }

        // Latest receipt
        $latestReceipt = $receipt->latest();
        if ($latestReceipt) {
            $this->newLine();
            $this->line('  Latest Deployment:');
            $this->line("    Deployed: {$latestReceipt->deployedAt->format('Y-m-d H:i:s')}");
            $this->line("    By: {$latestReceipt->deployedBy}");
            $this->line("    Duration: {$latestReceipt->durationSeconds}s");
            if ($latestReceipt->gitBranch) {
                $this->line("    Branch: {$latestReceipt->gitBranch}");
            }
            if ($latestReceipt->gitCommit) {
                $shortCommit = substr($latestReceipt->gitCommit, 0, 8);
                $this->line("    Commit: {$shortCommit}");
            }
            $status = $latestReceipt->success ? '<fg=green>success</>' : '<fg=red>failed</>';
            $this->line("    Status: {$status}");
        }

        $this->newLine();
    }

    private function renderPublicAssets(CommandService $cmd, DeploymentService $deployment): void
    {
        $this->info('Public Assets');
        $this->line(str_repeat('-', 50));

        $currentRelease = $deployment->getCurrentRelease();
        if (! $currentRelease) {
            $this->line('  <fg=yellow>Cannot check - no current release</>');
            $this->newLine();

            return;
        }

        $currentPath = $deployment->getReleasePath($currentRelease);
        $directories = [
            'public/fonts' => 'Font files',
            'public/css' => 'CSS stylesheets',
            'public/js' => 'JavaScript files',
            'public/build' => 'Vite/Mix build output',
            'public/images' => 'Image assets',
        ];

        foreach ($directories as $dir => $description) {
            $fullPath = "{$currentPath}/{$dir}";
            $this->renderDirectoryStatus($cmd, $dir, $fullPath, $description);
        }

        $this->newLine();
    }

    private function renderStorageDirectories(CommandService $cmd, DeploymentService $deployment): void
    {
        $this->info('Storage & Bootstrap');
        $this->line(str_repeat('-', 50));

        $currentRelease = $deployment->getCurrentRelease();
        if (! $currentRelease) {
            $this->line('  <fg=yellow>Cannot check - no current release</>');
            $this->newLine();

            return;
        }

        $currentPath = $deployment->getReleasePath($currentRelease);

        // Storage directories (top level)
        $storagePath = "{$currentPath}/storage";
        if ($cmd->directoryExists($storagePath)) {
            $this->line('  storage/ <fg=green>exists</>');

            // List top-level storage contents
            $output = trim($cmd->remote("ls -1 {$storagePath} 2>/dev/null || echo ''"));
            if (! empty($output)) {
                $items = array_filter(explode("\n", $output));
                foreach ($items as $item) {
                    $itemPath = "{$storagePath}/{$item}";
                    $isDir = $cmd->directoryExists($itemPath);
                    $icon = $isDir ? 'd' : 'f';
                    $this->line("    [{$icon}] {$item}");
                }
            }
        } else {
            $this->line('  storage/ <fg=red>missing</>');
            $this->addIssue('storage', 'Storage directory missing');
        }

        // Bootstrap cache
        $bootstrapCachePath = "{$currentPath}/bootstrap/cache";
        if ($cmd->directoryExists($bootstrapCachePath)) {
            $output = trim($cmd->remote("ls -1 {$bootstrapCachePath} 2>/dev/null | wc -l"));
            $count = (int) $output;
            $this->line("  bootstrap/cache/ <fg=green>exists</> ({$count} files)");

            // Check for important cache files
            $cacheFiles = ['config.php', 'routes-v7.php', 'services.php', 'packages.php'];
            foreach ($cacheFiles as $file) {
                $filePath = "{$bootstrapCachePath}/{$file}";
                if ($cmd->fileExists($filePath)) {
                    $this->line("    [f] {$file} <fg=green>cached</>");
                }
            }
        } else {
            $this->line('  bootstrap/cache/ <fg=red>missing</>');
            $this->addIssue('bootstrap', 'Bootstrap cache directory missing');
        }

        $this->newLine();
    }

    private function renderSymlinks(CommandService $cmd, DeploymentService $deployment): void
    {
        $this->info('Symlinks');
        $this->line(str_repeat('-', 50));

        $deployPath = $deployment->getDeployPath();
        $currentRelease = $deployment->getCurrentRelease();

        // Current symlink
        $currentSymlink = "{$deployPath}/".Paths::CURRENT_SYMLINK;
        $this->checkSymlink($cmd, 'current', $currentSymlink, $currentRelease ? "releases/{$currentRelease}" : null);

        // Storage symlink (in shared)
        $sharedStoragePath = "{$deployPath}/".Paths::SHARED_STORAGE;
        if ($cmd->directoryExists($sharedStoragePath)) {
            $this->line('  storage (shared) <fg=green>exists</>');
        } else {
            $this->line('  storage (shared) <fg=red>missing</>');
            $this->addIssue('symlink', 'Shared storage directory missing');
        }

        // Release storage → shared storage symlink
        if ($currentRelease) {
            $releaseStoragePath = "{$deployPath}/releases/{$currentRelease}/storage";
            if ($cmd->symlinkExists($releaseStoragePath)) {
                $target = trim($cmd->remote("readlink {$releaseStoragePath} 2>/dev/null || echo ''"));
                $this->line("  release/storage -> <fg=cyan>{$target}</>");

                // Verify it points to shared storage
                $expectedTarget = "{$deployPath}/".Paths::SHARED_STORAGE;
                if ($target !== $expectedTarget && ! str_ends_with($target, '/shared/storage')) {
                    $this->addIssue('symlink', "Release storage symlink points to {$target}, expected shared/storage");
                }
            } else {
                $this->line('  release/storage <fg=yellow>not a symlink</>');
            }

            // Public storage symlink
            $publicStoragePath = "{$deployPath}/releases/{$currentRelease}/public/storage";
            if ($cmd->symlinkExists($publicStoragePath)) {
                $target = trim($cmd->remote("readlink {$publicStoragePath} 2>/dev/null || echo ''"));
                $this->line("  public/storage -> <fg=cyan>{$target}</>");
            } elseif ($cmd->directoryExists($publicStoragePath)) {
                $this->line('  public/storage <fg=yellow>directory (not symlink)</>');
                $this->addIssue('symlink', 'public/storage is a directory instead of symlink');
            } else {
                $this->line('  public/storage <fg=yellow>missing</>');
            }
        }

        // .env symlink
        if ($currentRelease) {
            $envPath = "{$deployPath}/releases/{$currentRelease}/.env";
            if ($cmd->symlinkExists($envPath)) {
                $target = trim($cmd->remote("readlink {$envPath} 2>/dev/null || echo ''"));
                $this->line("  .env -> <fg=cyan>{$target}</>");
            } elseif ($cmd->fileExists($envPath)) {
                $this->line('  .env <fg=yellow>file (not symlink)</>');
            } else {
                $this->line('  .env <fg=red>missing</>');
                $this->addIssue('symlink', '.env file/symlink missing');
            }
        }

        $this->newLine();
    }

    private function checkSymlink(CommandService $cmd, string $name, string $path, ?string $expectedTarget): void
    {
        if ($cmd->symlinkExists($path)) {
            $actualTarget = trim($cmd->remote("readlink {$path} 2>/dev/null || echo ''"));
            $basename = basename($actualTarget);

            if ($expectedTarget && ! str_contains($actualTarget, $expectedTarget)) {
                $this->line("  {$name} -> <fg=yellow>{$basename}</> (expected: {$expectedTarget})");
                $this->addIssue('symlink', "{$name} symlink points to {$actualTarget}, expected {$expectedTarget}");
            } else {
                $this->line("  {$name} -> <fg=green>{$basename}</>");
            }
        } elseif ($cmd->pathExists($path)) {
            $this->line("  {$name} <fg=yellow>exists but not a symlink</>");
            $this->addIssue('symlink', "{$name} exists but is not a symlink");
        } else {
            $this->line("  {$name} <fg=red>missing</>");
            $this->addIssue('symlink', "{$name} symlink missing");
        }
    }

    private function renderPermissions(CommandService $cmd, DeploymentService $deployment): void
    {
        $this->info('Key File Permissions');
        $this->line(str_repeat('-', 50));

        $currentRelease = $deployment->getCurrentRelease();
        if (! $currentRelease) {
            $this->line('  <fg=yellow>Cannot check - no current release</>');
            $this->newLine();

            return;
        }

        $currentPath = $deployment->getReleasePath($currentRelease);

        // Key paths to check
        $pathsToCheck = [
            'bootstrap/cache',
            'storage',
            'storage/logs',
            'storage/framework/views',
            'storage/framework/cache',
            'storage/framework/sessions',
        ];

        foreach ($pathsToCheck as $relativePath) {
            $fullPath = "{$currentPath}/{$relativePath}";
            if ($cmd->pathExists($fullPath)) {
                // Get permissions and ownership
                $stat = trim($cmd->remote("stat -c '%A %U:%G' {$fullPath} 2>/dev/null || echo 'unknown'"));
                $parts = explode(' ', $stat);
                $perms = $parts[0] ?? 'unknown';
                $owner = $parts[1] ?? 'unknown';

                // Check if writable by web group
                $isWritable = str_contains($perms, 'w');
                $permColor = $isWritable ? 'green' : 'yellow';

                $this->line("  {$relativePath}");
                $this->line("    <fg={$permColor}>{$perms}</> {$owner}");
            }
        }

        // Check .env permissions
        $envPath = "{$currentPath}/.env";
        if ($cmd->pathExists($envPath)) {
            $stat = trim($cmd->remote("stat -c '%A %U:%G' {$envPath} 2>/dev/null || echo 'unknown'"));
            $this->line('  .env');
            $this->line("    {$stat}");

            // Warn if .env is world-readable
            if (str_contains($stat, 'r--') && preg_match('/r.{2}r.{2}r/', $stat)) {
                $this->addIssue('permissions', '.env file is world-readable (security risk)');
            }
        }

        $this->newLine();
    }

    private function renderComparison(CommandService $cmd, DeploymentService $deployment, string $localBasePath): void
    {
        $this->info('Local vs Remote Comparison');
        $this->line(str_repeat('-', 50));

        $currentRelease = $deployment->getCurrentRelease();
        if (! $currentRelease) {
            $this->line('  <fg=yellow>Cannot compare - no current release</>');
            $this->newLine();

            return;
        }

        $currentPath = $deployment->getReleasePath($currentRelease);

        // Directories to compare
        $compareDirectories = [
            'public/fonts',
            'public/css',
            'public/js',
            'public/build',
        ];

        foreach ($compareDirectories as $dir) {
            $localDir = "{$localBasePath}/{$dir}";
            $remoteDir = "{$currentPath}/{$dir}";

            if (! is_dir($localDir)) {
                continue;
            }

            $this->line("  {$dir}/");

            // Get local files
            $localFiles = $this->getLocalFiles($localDir);

            // Get remote files
            $remoteFiles = [];
            if ($cmd->directoryExists($remoteDir)) {
                $output = trim($cmd->remote("find {$remoteDir} -type f -printf '%P\n' 2>/dev/null || echo ''"));
                if (! empty($output)) {
                    $remoteFiles = array_filter(explode("\n", $output));
                }
            }

            // Files only in local
            $localOnly = array_diff($localFiles, $remoteFiles);
            if (! empty($localOnly)) {
                $this->line('    <fg=yellow>Local only:</>');
                $displayCount = 0;
                foreach ($localOnly as $file) {
                    if ($displayCount++ < 5) {
                        $this->line("      + {$file}");
                    }
                }
                if (count($localOnly) > 5) {
                    $remaining = count($localOnly) - 5;
                    $this->line("      <fg=gray>... and {$remaining} more</>");
                }
            }

            // Files only on remote
            $remoteOnly = array_diff($remoteFiles, $localFiles);
            if (! empty($remoteOnly)) {
                $this->line('    <fg=yellow>Remote only:</>');
                $displayCount = 0;
                foreach ($remoteOnly as $file) {
                    if ($displayCount++ < 5) {
                        $this->line("      - {$file}");
                    }
                }
                if (count($remoteOnly) > 5) {
                    $remaining = count($remoteOnly) - 5;
                    $this->line("      <fg=gray>... and {$remaining} more</>");
                }
            }

            // Summary
            $matchCount = count(array_intersect($localFiles, $remoteFiles));
            if (empty($localOnly) && empty($remoteOnly)) {
                $this->line("    <fg=green>All {$matchCount} files match</>");
            } else {
                $this->line("    {$matchCount} matching, ".count($localOnly).' local-only, '.count($remoteOnly).' remote-only');
            }
        }

        $this->newLine();
    }

    /**
     * @return array<string>
     */
    private function getLocalFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($directory.'/', '', $file->getPathname());
                $files[] = $relativePath;
            }
        }

        return $files;
    }

    private function renderDirectoryStatus(CommandService $cmd, string $name, string $path, string $description): void
    {
        if ($cmd->directoryExists($path)) {
            // Count files
            $output = trim($cmd->remote("find {$path} -type f 2>/dev/null | wc -l"));
            $count = (int) $output;

            if ($count > 0) {
                $this->line("  {$name}/ <fg=green>exists</> ({$count} files)");
            } else {
                $this->line("  {$name}/ <fg=yellow>empty</>");
            }
        } else {
            // Check if optional directory
            if (str_contains($name, 'build') || str_contains($name, 'images')) {
                $this->line("  {$name}/ <fg=gray>not present</>");
            } else {
                $this->line("  {$name}/ <fg=red>missing</>");
                $this->addIssue('assets', "{$name} directory missing - {$description}");
            }
        }
    }

    private function addIssue(string $category, string $message): void
    {
        $this->issues[] = [
            'category' => $category,
            'message' => $message,
        ];
    }

    private function renderIssuesSummary(): void
    {
        if (empty($this->issues)) {
            $this->line('<fg=green>═══════════════════════════════════════════════════════════</>');
            $this->line('<fg=green>  No issues detected - deployment looks healthy!</>');
            $this->line('<fg=green>═══════════════════════════════════════════════════════════</>');

            return;
        }

        $this->line('<fg=yellow>═══════════════════════════════════════════════════════════</>');
        $this->line('<fg=yellow>  Potential Issues Found</>');
        $this->line('<fg=yellow>═══════════════════════════════════════════════════════════</>');
        $this->newLine();

        // Group by category
        $grouped = [];
        foreach ($this->issues as $issue) {
            $grouped[$issue['category']][] = $issue['message'];
        }

        foreach ($grouped as $category => $messages) {
            $categoryLabel = ucfirst($category);
            $this->line("  <fg=yellow>[{$categoryLabel}]</>");
            foreach ($messages as $message) {
                $this->line("    - {$message}");
            }
            $this->newLine();
        }

        $this->line('Run with <fg=cyan>-v</> for more detailed output.');
    }
}
