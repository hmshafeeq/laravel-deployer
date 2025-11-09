# Implementation Roadmap: Simplified Deployer

## 🎯 Overview

Transform the over-complicated 17-action refactor into a clean, maintainable 6-action architecture.

**Total Estimated Time**: 8-12 hours
**Difficulty**: Medium
**Impact**: Massive improvement in maintainability

---

## 📋 Phase 1: Create Consolidated Services (3-4 hours)

### Step 1.1: Create `CommandService` ⭐⭐
**Time**: 90 minutes
**Complexity**: Medium

**Merges**:
- `LocalCommandExecutor`
- `RemoteCommandExecutor`
- `OutputService`
- `ArtisanTaskRunner`

**File**: `src/Services/CommandService.php`

**Structure**:
```php
<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Symfony\Component\Console\Output\OutputInterface;
use Spatie\Ssh\Ssh;

class CommandService
{
    private Ssh $ssh;

    public function __construct(
        private DeploymentConfig $config,
        private OutputInterface $output
    ) {
        if (!$config->isLocal) {
            $this->ssh = Ssh::create($config->remoteUser, $config->hostname)
                ->disableStrictHostKeyChecking()
                ->disablePasswordAuthentication();
        }
    }

    // Remote execution
    public function remote(string $command): string
    {
        $this->debug("run {$command}");
        $result = $this->ssh->execute($command);
        $output = trim($result->getOutput());
        $this->veryVerbose($output);
        return $output;
    }

    // Local execution
    public function local(string $command): string
    {
        $this->debug("run {$command}");
        $result = shell_exec($command);
        $this->veryVerbose($result);
        return trim($result);
    }

    // Artisan commands
    public function artisan(string $command, array $options = []): string
    {
        $optionsStr = $this->buildOptions($options);
        $fullCommand = "/usr/bin/php artisan {$command}{$optionsStr}";
        $this->info("Running artisan {$command}");
        return $this->remote($fullCommand);
    }

    // Test conditions
    public function test(string $condition): bool
    {
        $result = $this->remote($condition . ' && echo "true" || echo "false"');
        return trim($result) === 'true';
    }

    // File operations
    public function fileExists(string $path): bool
    {
        return $this->test("[ -f {$path} ]");
    }

    public function directoryExists(string $path): bool
    {
        return $this->test("[ -d {$path} ]");
    }

    // Output methods
    public function info(string $message): void
    {
        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) {
            $this->output->writeln("<info>{$message}</info>");
        }
    }

    public function success(string $message): void
    {
        $this->output->writeln("<info>✓ {$message}</info>");
    }

    public function error(string $message): void
    {
        $this->output->writeln("<error>✗ {$message}</error>");
    }

    public function debug(string $message): void
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln("<comment>{$message}</comment>");
        }
    }

    public function veryVerbose(string $message): void
    {
        if ($this->output->isVeryVerbose()) {
            foreach (explode("\n", trim($message)) as $line) {
                $this->output->writeln("  {$line}");
            }
        }
    }

    private function buildOptions(array $options): string
    {
        $parts = [];
        foreach ($options as $key => $value) {
            if (is_int($key)) {
                $parts[] = $value;
            } else {
                $parts[] = "--{$key}={$value}";
            }
        }
        return empty($parts) ? '' : ' ' . implode(' ', $parts);
    }
}
```

**Testing**:
```php
$cmd = new CommandService($config, $output);
$cmd->remote("ls -la");
$cmd->local("npm run build");
$cmd->artisan("migrate", ['--force']);
$cmd->test("[ -f /path/to/file ]");
```

---

### Step 1.2: Create `DeploymentService` ⭐⭐
**Time**: 90 minutes
**Complexity**: Medium

**Merges**:
- `DeploymentServiceFactory`
- `DeploymentOperationsService`
- `ReleaseManager`
- `LockManager`

**File**: `src/Services/DeploymentService.php`

**Structure**:
```php
<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\ReleaseInfo;
use Shaf\LaravelDeployer\Exceptions\DeploymentException;

class DeploymentService
{
    private CommandService $cmd;

    public function __construct(
        private DeploymentConfig $config,
        private string $basePath
    ) {
    }

    public function setCommandService(CommandService $cmd): void
    {
        $this->cmd = $cmd;
    }

    // Release management
    public function generateReleaseName(): string
    {
        $yearMonth = date('Ym');
        $counterDir = "{$this->config->deployPath}/.dep/release_counter";
        $counterFile = "{$counterDir}/{$yearMonth}.txt";

        $this->cmd->remote("mkdir -p {$counterDir}");

        $count = $this->cmd->remote("if [ -f {$counterFile} ]; then cat {$counterFile}; else echo 0; fi");
        $count = (int)$count + 1;

        $this->cmd->remote("echo {$count} > {$counterFile}");

        return "{$yearMonth}.{$count}";
    }

    public function getReleases(): array
    {
        $releasesPath = "{$this->config->deployPath}/releases";

        if (!$this->cmd->directoryExists($releasesPath)) {
            return [];
        }

        $releases = $this->cmd->remote("ls -1 {$releasesPath}");
        return array_filter(explode("\n", trim($releases)));
    }

    public function getCurrentRelease(): ?string
    {
        $currentPath = "{$this->config->deployPath}/current";

        if (!$this->cmd->test("[ -L {$currentPath} ]")) {
            return null;
        }

        $target = $this->cmd->remote("readlink {$currentPath}");
        return basename(trim($target));
    }

    public function getPreviousRelease(): ?string
    {
        $current = $this->getCurrentRelease();
        $releases = $this->getReleases();

        if (empty($releases) || count($releases) < 2) {
            return null;
        }

        rsort($releases);

        foreach ($releases as $release) {
            if ($release !== $current) {
                return $release;
            }
        }

        return null;
    }

    // Lock management
    public function lock(): void
    {
        $lockFile = "{$this->config->deployPath}/.dep/deploy.lock";

        if ($this->isLocked()) {
            throw DeploymentException::locked($lockFile);
        }

        $this->cmd->remote("mkdir -p {$this->config->deployPath}/.dep");
        $this->cmd->remote("touch {$lockFile}");
    }

    public function unlock(): void
    {
        $lockFile = "{$this->config->deployPath}/.dep/deploy.lock";
        $this->cmd->remote("rm -f {$lockFile}");
    }

    public function isLocked(): bool
    {
        $lockFile = "{$this->config->deployPath}/.dep/deploy.lock";
        return $this->cmd->fileExists($lockFile);
    }

    // Path helpers
    public function getReleasePath(string $releaseName): string
    {
        return "{$this->config->deployPath}/releases/{$releaseName}";
    }

    public function getSharedPath(): string
    {
        return "{$this->config->deployPath}/shared";
    }

    public function getCurrentPath(): string
    {
        return "{$this->config->deployPath}/current";
    }
}
```

---

### Step 1.3: Simplify `ConfigService` ⭐
**Time**: 30 minutes
**Complexity**: Low

**Action**: Rename `ConfigurationService` to `ConfigService` and simplify slightly.

**File**: `src/Services/ConfigService.php`

Keep most of the current implementation, just:
- Rename class
- Add static helper method for easy loading
- Clean up any unnecessary complexity

---

### Step 1.4: Keep `RsyncService` ✅
**Time**: 0 minutes
**Complexity**: N/A

**Action**: Keep as-is, already good.

---

## 📋 Phase 2: Create Cohesive Actions (4-5 hours)

### Step 2.1: Create `DeployAction` ⭐⭐⭐
**Time**: 120 minutes
**Complexity**: High
**Priority**: Critical

**File**: `src/Actions/DeployAction.php`

**Structure**:
```php
<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\DeploymentService;
use Shaf\LaravelDeployer\Services\RsyncService;

class DeployAction
{
    private string $releaseName;

    public function __construct(
        private DeploymentService $deployment,
        private CommandService $cmd,
        private RsyncService $rsync,
        private DeploymentConfig $config
    ) {
        $this->deployment->setCommandService($cmd);
    }

    public function execute(): void
    {
        $this->cmd->info("Starting deployment to {$this->config->environment->value}");

        // 1. Lock deployment
        $this->lock();

        try {
            // 2. Setup deployment structure
            $this->setupStructure();

            // 3. Generate release name
            $this->releaseName = $this->deployment->generateReleaseName();
            $this->cmd->success("Release: {$this->releaseName}");

            // 4. Create release directory
            $this->createRelease();

            // 5. Build assets locally
            if (!$this->config->isLocal) {
                $this->buildAssets();
            }

            // 6. Sync files to server
            $this->syncFiles();

            // 7. Create shared symlinks
            $this->createSharedLinks();

            // 8. Install composer dependencies
            $this->composerInstall();

            // 9. Run migrations
            $this->runMigrations();

            // 10. Symlink current to release
            $this->symlinkRelease();

            // 11. Cleanup old releases
            $this->cleanupReleases();

            $this->cmd->success("Deployment completed successfully!");

        } finally {
            // 12. Always unlock
            $this->unlock();
        }
    }

    private function lock(): void
    {
        $this->cmd->info("Locking deployment...");
        $this->deployment->lock();
    }

    private function setupStructure(): void
    {
        $this->cmd->info("Setting up deployment structure...");

        $paths = [
            $this->config->deployPath,
            "{$this->config->deployPath}/releases",
            "{$this->config->deployPath}/shared",
            "{$this->config->deployPath}/.dep",
        ];

        foreach ($paths as $path) {
            $this->cmd->remote("mkdir -p {$path}");
        }
    }

    private function createRelease(): void
    {
        $this->cmd->info("Creating release directory...");
        $releasePath = $this->deployment->getReleasePath($this->releaseName);
        $this->cmd->remote("mkdir -p {$releasePath}");
    }

    private function buildAssets(): void
    {
        $this->cmd->info("Building assets...");
        $this->cmd->local("npm run build");
    }

    private function syncFiles(): void
    {
        $this->cmd->info("Syncing files to server...");
        $destination = "{$this->config->remoteUser}@{$this->config->hostname}:{$this->deployment->getReleasePath($this->releaseName)}";
        $this->rsync->sync($destination);
    }

    private function createSharedLinks(): void
    {
        $this->cmd->info("Creating shared symlinks...");
        $releasePath = $this->deployment->getReleasePath($this->releaseName);
        $sharedPath = $this->deployment->getSharedPath();

        $sharedItems = ['storage'];

        foreach ($sharedItems as $item) {
            $this->cmd->remote("rm -rf {$releasePath}/{$item}");
            $this->cmd->remote("ln -nfs {$sharedPath}/{$item} {$releasePath}/{$item}");
        }
    }

    private function composerInstall(): void
    {
        $this->cmd->info("Installing composer dependencies...");
        $releasePath = $this->deployment->getReleasePath($this->releaseName);
        $this->cmd->remote("cd {$releasePath} && composer install {$this->config->composerOptions}");
    }

    private function runMigrations(): void
    {
        $this->cmd->info("Running migrations...");
        $releasePath = $this->deployment->getReleasePath($this->releaseName);
        $this->cmd->remote("cd {$releasePath} && php artisan migrate --force");
    }

    private function symlinkRelease(): void
    {
        $this->cmd->info("Symlinking release...");
        $releasePath = $this->deployment->getReleasePath($this->releaseName);
        $currentPath = $this->deployment->getCurrentPath();

        $this->cmd->remote("ln -nfs {$releasePath} {$currentPath}");
    }

    private function cleanupReleases(): void
    {
        $this->cmd->info("Cleaning up old releases...");

        $releases = $this->deployment->getReleases();
        $keepReleases = $this->config->keepReleases;

        if (count($releases) <= $keepReleases) {
            return;
        }

        rsort($releases);
        $toDelete = array_slice($releases, $keepReleases);

        foreach ($toDelete as $release) {
            $path = $this->deployment->getReleasePath($release);
            $this->cmd->remote("rm -rf {$path}");
            $this->cmd->debug("Deleted release: {$release}");
        }
    }

    private function unlock(): void
    {
        $this->cmd->info("Unlocking deployment...");
        $this->deployment->unlock();
    }

    public function getReleaseName(): string
    {
        return $this->releaseName;
    }
}
```

---

### Step 2.2: Create `RollbackAction` ⭐
**Time**: 45 minutes
**Complexity**: Low

**File**: `src/Actions/RollbackAction.php`

---

### Step 2.3: Create `DatabaseAction` ⭐⭐
**Time**: 60 minutes
**Complexity**: Medium

**File**: `src/Actions/DatabaseAction.php`

---

### Step 2.4: Create `HealthCheckAction` ⭐
**Time**: 45 minutes
**Complexity**: Low

**File**: `src/Actions/HealthCheckAction.php`

---

### Step 2.5: Create `OptimizeAction` ⭐
**Time**: 45 minutes
**Complexity**: Low

**File**: `src/Actions/OptimizeAction.php`

---

### Step 2.6: Create `NotificationAction` ⭐
**Time**: 30 minutes
**Complexity**: Low

**File**: `src/Actions/NotificationAction.php`

---

## 📋 Phase 3: Update Commands (2-3 hours)

### Step 3.1: Update `DeployCommand` ⭐⭐
**Time**: 60 minutes

**File**: `src/Commands/DeployCommand.php`

**New Implementation**:
```php
<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Shaf\LaravelDeployer\Actions\DeployAction;
use Shaf\LaravelDeployer\Actions\HealthCheckAction;
use Shaf\LaravelDeployer\Actions\OptimizeAction;
use Shaf\LaravelDeployer\Actions\NotificationAction;
use Shaf\LaravelDeployer\Services\ConfigService;
use Shaf\LaravelDeployer\Services\DeploymentService;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\RsyncService;

class DeployCommand extends Command
{
    protected $signature = 'deploy {environment=staging}';
    protected $description = 'Deploy the application';

    public function handle(): int
    {
        $environment = $this->argument('environment');

        // Load configuration
        $config = ConfigService::load($environment, base_path());

        // Initialize services
        $deployService = new DeploymentService($config, base_path());
        $cmdService = new CommandService($config, $this->output);
        $rsyncService = new RsyncService($config, base_path());

        // Health check
        $healthCheck = new HealthCheckAction($cmdService, $config);
        if (!$healthCheck->check()) {
            $this->error('Health check failed!');
            return self::FAILURE;
        }

        try {
            // Deploy
            $deploy = new DeployAction($deployService, $cmdService, $rsyncService, $config);
            $deploy->execute();

            // Optimize
            $optimize = new OptimizeAction($cmdService, $config);
            $optimize->execute();

            // Notify success
            $notify = new NotificationAction($config);
            $notify->success([
                'environment' => $config->environment->value,
                'release' => $deploy->getReleaseName(),
            ]);

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error($e->getMessage());

            $notify = new NotificationAction($config);
            $notify->failure($e);

            return self::FAILURE;
        }
    }
}
```

**From 275 lines → ~60 lines!** 🎉

---

### Step 3.2: Update `RollbackCommand` ⭐
**Time**: 30 minutes

### Step 3.3: Update Database Commands ⭐
**Time**: 30 minutes each (3 commands = 90 minutes)

---

## 📋 Phase 4: Cleanup & Testing (1-2 hours)

### Step 4.1: Delete Old Files ⭐
**Time**: 30 minutes

**Files to delete**:
```bash
# Delete old actions (11 files)
rm src/Actions/Database/BackupDatabaseAction.php
rm src/Actions/Database/DownloadDatabaseAction.php
rm src/Actions/Deployment/BuildAssetsAction.php
rm src/Actions/Deployment/CreateReleaseAction.php
rm src/Actions/Deployment/LockDeploymentAction.php
rm src/Actions/Deployment/RollbackReleaseAction.php
rm src/Actions/Deployment/SetupDeploymentStructureAction.php
rm src/Actions/Deployment/SymlinkReleaseAction.php
rm src/Actions/Deployment/SyncFilesAction.php
rm src/Actions/Health/CheckEndpointsAction.php
rm src/Actions/Health/CheckServerResourcesAction.php
rm src/Actions/Notification/SendFailureNotificationAction.php
rm src/Actions/Notification/SendSuccessNotificationAction.php
rm src/Actions/System/ClearCachesAction.php
rm src/Actions/System/ReloadSupervisorAction.php
rm src/Actions/System/RestartNginxAction.php
rm src/Actions/System/RestartPhpFpmAction.php

# Delete old action directories
rmdir src/Actions/Database
rmdir src/Actions/Deployment
rmdir src/Actions/Health
rmdir src/Actions/Notification
rmdir src/Actions/System

# Delete old services (6 files)
rm src/Services/ArtisanTaskRunner.php
rm src/Services/ConfigurationService.php
rm src/Services/DeploymentOperationsService.php
rm src/Services/DeploymentServiceFactory.php
rm src/Services/LocalCommandExecutor.php
rm src/Services/LockManager.php
rm src/Services/OutputService.php
rm src/Services/ReleaseManager.php
rm src/Services/RemoteCommandExecutor.php

# Delete contracts directory
rm -rf src/Contracts

# Delete unnecessary enums
rm src/Enums/VerbosityLevel.php
rm src/Enums/TaskStatus.php
rm src/Data/TaskResult.php
```

---

### Step 4.2: Update Tests ⭐
**Time**: 60 minutes

Create/update tests for new actions:
- `DeployActionTest`
- `RollbackActionTest`
- `DatabaseActionTest`
- `HealthCheckActionTest`
- `OptimizeActionTest`

---

### Step 4.3: Update Documentation ⭐
**Time**: 30 minutes

Update:
- README.md
- Add usage examples
- Document each action

---

## ✅ Completion Checklist

### Phase 1: Services
- [ ] Create `CommandService` (90 min)
- [ ] Create `DeploymentService` (90 min)
- [ ] Simplify `ConfigService` (30 min)
- [ ] Keep `RsyncService` (0 min)

### Phase 2: Actions
- [ ] Create `DeployAction` (120 min)
- [ ] Create `RollbackAction` (45 min)
- [ ] Create `DatabaseAction` (60 min)
- [ ] Create `HealthCheckAction` (45 min)
- [ ] Create `OptimizeAction` (45 min)
- [ ] Create `NotificationAction` (30 min)

### Phase 3: Commands
- [ ] Update `DeployCommand` (60 min)
- [ ] Update `RollbackCommand` (30 min)
- [ ] Update `DatabaseBackupCommand` (30 min)
- [ ] Update `DatabaseDownloadCommand` (30 min)
- [ ] Update `DatabaseUploadCommand` (30 min)

### Phase 4: Cleanup
- [ ] Delete old action files (30 min)
- [ ] Delete old service files
- [ ] Delete old contracts
- [ ] Update tests (60 min)
- [ ] Update documentation (30 min)

**Total Time**: ~12 hours

---

## 🎯 Success Metrics

### Before Starting
- 17 action files
- 10+ service files
- ~275 lines in DeployCommand
- Hard to understand
- Hard to maintain

### After Completion
- 6 action files ✅
- 4 service files ✅
- ~60 lines in DeployCommand ✅
- Easy to understand ✅
- Easy to maintain ✅
- Can use from web/jobs ✅

---

## 📝 Notes

- Work incrementally, one phase at a time
- Test after each phase
- Commit after each major step
- Don't rush - quality over speed
- Keep it simple!

---

**Last Updated**: 2025-01-09
**Branch**: `claude/simplified-deployer-actions-011CUxD4n9WcWreL2o5xcSAv`
**Status**: Planning Complete - Ready to Implement
