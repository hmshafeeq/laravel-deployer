# DeploymentTasks Action Refactoring Strategy
## Strategic 5-8 Action Approach

> **Goal**: Reduce DeploymentTasks.php from 649 lines to ~120 lines with 5-8 core actions, moving supporting logic to services and utilities.

---

## 🎯 Current Analysis

### DeploymentTasks.php - 649 lines, 28 methods

**Categories:**
1. **Setup/Preparation** (4 methods, ~100 lines)
   - setup(), checkLock(), lock(), unlock()

2. **Release Management** (1 method, ~85 lines)
   - release()

3. **Code Deployment** (2 methods, ~30 lines)
   - buildAssets(), rsync()

4. **Configuration** (3 methods, ~190 lines)
   - shared(), writable(), vendors()

5. **Activation** (2 methods, ~35 lines)
   - symlink(), cleanup()

6. **Artisan Commands** (7 methods, ~140 lines - already simplified)
   - artisanStorageLink(), artisanConfigCache(), etc.

7. **Post-deployment** (3 methods, ~45 lines)
   - success(), linkDep(), postDeployment()

8. **Rollback** (4 methods, ~125 lines)
   - getReleases(), getCurrentRelease(), rollback(), getRollbackInfo()

---

## 🏗️ Proposed Architecture

### Core Actions (6 actions - the essentials)

```
src/Actions/Deployment/
├── 1. PrepareDeploymentAction.php (~60 lines)
│   └── Setup dirs, lock deployment, generate release name
│
├── 2. SyncCodeAction.php (~40 lines)
│   └── Rsync files to release directory
│
├── 3. ConfigureReleaseAction.php (~80 lines)
│   └── Link shared resources, install vendors, set permissions
│
├── 4. OptimizeApplicationAction.php (~50 lines)
│   └── Run artisan optimizations (config, route, view cache)
│
├── 5. ActivateReleaseAction.php (~45 lines)
│   └── Symlink to current, cleanup old releases
│
└── 6. RollbackDeploymentAction.php (~50 lines)
    └── Rollback to previous release
```

**Total Actions: 325 lines (vs 649 in original)**

---

### Support Services (moved from DeploymentTasks)

```
src/Services/
├── ReleaseManager.php (~100 lines)
│   ├── generateReleaseName()
│   ├── getReleases()
│   ├── getCurrentRelease()
│   ├── getRollbackInfo()
│   └── createReleaseDirectories()
│
├── LockManager.php (~50 lines)
│   ├── isLocked()
│   ├── lock()
│   ├── unlock()
│   └── getLockInfo()
│
├── PermissionManager.php (~80 lines)
│   ├── setWritableDirectories()
│   ├── setAclPermissions()
│   ├── detectWebServerUser()
│   └── hasSetfacl()
│
└── SharedResourceLinker.php (~60 lines)
    ├── linkStorage()
    ├── linkEnvironmentFile()
    └── linkCustomResources()
```

**Total Services: ~290 lines**

---

### Utility Helpers (simple functions)

```
helpers/deployment.php (~50 lines)
├── deploy_path()
├── release_path()
├── shared_path()
├── current_path()
├── is_symlink()
└── create_symlink()
```

---

## 📋 Detailed Action Breakdown

### 1. PrepareDeploymentAction

**Purpose:** Setup deployment environment and prepare release
**Lines:** ~60

```php
class PrepareDeploymentAction extends DeploymentAction
{
    public function execute(): string
    {
        $this->setupDirectories();

        $this->checkAndLock();

        $releaseName = $this->releaseManager->generateReleaseName();
        $this->releaseManager->createReleaseDirectories($releaseName);

        $this->writeln("✅ Prepared release: {$releaseName}");

        return $releaseName;
    }

    protected function setupDirectories(): void
    {
        // Uses ReleaseManager service
    }

    protected function checkAndLock(): void
    {
        // Uses LockManager service
    }
}
```

**Replaces:**
- setup() - 25 lines
- checkLock() - 15 lines
- lock() - 15 lines
- release() - 85 lines (partial)

---

### 2. SyncCodeAction

**Purpose:** Sync code to release directory via rsync
**Lines:** ~40

```php
class SyncCodeAction extends DeploymentAction
{
    public function execute(string $releaseName): void
    {
        $this->validateRelease($releaseName);

        $this->writeln("📦 Syncing code to release {$releaseName}...");

        $this->deployer->runRsync();

        $this->writeln("✅ Code synced successfully");
    }
}
```

**Replaces:**
- rsync() - 20 lines
- buildAssets() - 7 lines (if needed, otherwise separate)

---

### 3. ConfigureReleaseAction

**Purpose:** Configure release with shared resources, vendors, permissions
**Lines:** ~80

```php
class ConfigureReleaseAction extends DeploymentAction
{
    public function __construct(
        protected Deployer $deployer,
        protected SharedResourceLinker $linker,
        protected PermissionManager $permissions,
        protected SystemCommandDetector $systemDetector
    ) {
        parent::__construct($deployer);
    }

    public function execute(string $releaseName): void
    {
        $this->writeln("⚙️  Configuring release {$releaseName}...");

        // Link shared resources
        $this->linker->linkStorage();
        $this->linker->linkEnvironmentFile();

        // Install vendors
        $this->installComposerDependencies();

        // Set permissions
        $this->permissions->setWritableDirectories();
        $this->permissions->setAclPermissions();

        $this->writeln("✅ Release configured");
    }

    protected function installComposerDependencies(): void
    {
        $composerPath = $this->systemDetector->getComposerPath();
        $phpPath = $this->systemDetector->getPhpPath();
        $options = config('laravel-deployer.composer.options');

        // Run composer install
        $command = "cd {$this->getReleasePath()} && {$phpPath} {$composerPath} install {$options}";
        $this->run($command);
    }
}
```

**Replaces:**
- shared() - 48 lines
- writable() - 75 lines
- vendors() - 32 lines
- fixModulePermissions() - 17 lines

---

### 4. OptimizeApplicationAction

**Purpose:** Run artisan optimization commands
**Lines:** ~50

```php
class OptimizeApplicationAction extends DeploymentAction
{
    public function __construct(
        protected Deployer $deployer,
        protected ArtisanCommandRunner $artisan
    ) {
        parent::__construct($deployer);
    }

    public function execute(string $releaseName): void
    {
        $releasePath = $this->deployer->getReleasePath();

        $this->writeln("🚀 Optimizing application...");

        // Config cache
        $this->artisan->run('config:cache', $releasePath);

        // Route cache
        $this->artisan->run('route:cache', $releasePath);

        // View cache
        $this->artisan->run('view:cache', $releasePath);

        // General optimize
        $this->artisan->run('optimize', $releasePath);

        $this->writeln("✅ Application optimized");
    }
}
```

**Replaces:**
- artisanConfigCache() - 7 lines
- artisanRouteCache() - 7 lines
- artisanViewCache() - 7 lines
- artisanOptimize() - 7 lines

---

### 5. ActivateReleaseAction

**Purpose:** Activate release and cleanup old releases
**Lines:** ~45

```php
class ActivateReleaseAction extends DeploymentAction
{
    public function __construct(
        protected Deployer $deployer,
        protected LockManager $lockManager
    ) {
        parent::__construct($deployer);
    }

    public function execute(string $releaseName): void
    {
        $this->writeln("🔄 Activating release {$releaseName}...");

        $this->symlinkRelease($releaseName);
        $this->cleanupOldReleases();
        $this->lockManager->unlock();

        $this->writeln("✅ Release activated!");
    }

    protected function symlinkRelease(string $releaseName): void
    {
        $deployPath = $this->getDeployPath();

        // Create temp symlink
        $this->run("ln -nfs --relative releases/{$releaseName} {$deployPath}/release");

        // Atomic swap
        $this->run("mv -T {$deployPath}/release {$deployPath}/current");
    }

    protected function cleanupOldReleases(): void
    {
        $keepReleases = config('laravel-deployer.paths.keep_releases', 3);
        $deployPath = $this->getDeployPath();

        $releases = $this->run("cd {$deployPath}/releases && ls -t -1 -d */ | tail -n +".($keepReleases + 1));

        if (!empty($releases)) {
            $releasesToDelete = explode("\n", trim($releases));
            foreach ($releasesToDelete as $release) {
                $release = trim($release, '/');
                $this->run("rm -rf {$deployPath}/releases/{$release}");
            }
        }
    }
}
```

**Replaces:**
- symlink() - 15 lines
- cleanup() - 20 lines
- unlock() - 5 lines

---

### 6. RollbackDeploymentAction

**Purpose:** Rollback to previous release
**Lines:** ~50

```php
class RollbackDeploymentAction extends DeploymentAction
{
    public function __construct(
        protected Deployer $deployer,
        protected ReleaseManager $releaseManager
    ) {
        parent::__construct($deployer);
    }

    public function execute(?string $targetRelease = null): void
    {
        $rollbackInfo = $this->releaseManager->getRollbackInfo();

        if (!$rollbackInfo['can_rollback']) {
            throw new \RuntimeException("No previous release available for rollback");
        }

        $target = $targetRelease ?? $rollbackInfo['previous'];

        $this->writeln("🔄 Rolling back to release: {$target}");

        $this->releaseManager->switchToRelease($target);

        $this->writeln("✅ Rolled back to: {$target}");
    }
}
```

**Replaces:**
- rollback() - 25 lines
- getReleases() - 20 lines
- getCurrentRelease() - 18 lines
- getRollbackInfo() - 18 lines

---

## 🔧 Support Services Detail

### ReleaseManager Service

```php
class ReleaseManager
{
    public function __construct(protected Deployer $deployer) {}

    public function generateReleaseName(): string
    {
        $yearMonth = date('Ym');
        $counterDir = "{$this->getDeployPath()}/.dep/release_counter";
        $counterFile = "{$counterDir}/{$yearMonth}.txt";

        // Ensure folder exists
        $this->deployer->run("mkdir -p {$counterDir}");

        // Read counter or start from 0
        $count = $this->deployer->run("if [ -f {$counterFile} ]; then cat {$counterFile}; else echo 0; fi");
        $count = (int) $count + 1;

        // Save updated counter
        $this->deployer->run("echo {$count} > {$counterFile}");

        return "{$yearMonth}.{$count}";
    }

    public function createReleaseDirectories(string $releaseName): void
    {
        $deployPath = $this->getDeployPath();
        $this->deployer->run("mkdir -p {$deployPath}/releases/{$releaseName}");

        // Create symlink
        $this->deployer->run("ln -nfs --relative releases/{$releaseName} {$deployPath}/release");

        // Log the release
        $this->logRelease($releaseName);
    }

    public function getReleases(): array { /* ... */ }
    public function getCurrentRelease(): ?string { /* ... */ }
    public function getRollbackInfo(): array { /* ... */ }
    public function switchToRelease(string $releaseName): void { /* ... */ }

    protected function logRelease(string $releaseName): void { /* ... */ }
    protected function getDeployPath(): string { /* ... */ }
}
```

---

### LockManager Service

```php
class LockManager
{
    public function __construct(protected Deployer $deployer) {}

    public function isLocked(): bool
    {
        $lockFile = $this->getLockFile();
        $exists = $this->deployer->run("if [ -f {$lockFile} ]; then echo +locked; fi");
        return !empty($exists);
    }

    public function lock(): void
    {
        if ($this->isLocked()) {
            throw new \RuntimeException("Deployment is already locked");
        }

        $user = $this->deployer->runLocally('git config --get user.name');
        $lockFile = $this->getLockFile();

        $this->deployer->run("echo '{$user}' > {$lockFile}");
    }

    public function unlock(): void
    {
        $lockFile = $this->getLockFile();
        $this->deployer->run("rm -f {$lockFile}");
    }

    public function getLockInfo(): ?array
    {
        if (!$this->isLocked()) {
            return null;
        }

        $lockFile = $this->getLockFile();
        $user = $this->deployer->run("cat {$lockFile}");

        return ['user' => trim($user)];
    }

    protected function getLockFile(): string
    {
        return $this->deployer->getDeployPath() . '/.dep/deploy.lock';
    }
}
```

---

## 📊 Expected Results

### Line Count Comparison

```
BEFORE (DeploymentTasks.php): 649 lines

AFTER:
├── DeploymentTasks.php (orchestrator)      : ~120 lines
├── Actions (6 files)                       : ~325 lines
├── Services (4 files)                      : ~290 lines
├── Helpers                                 : ~50 lines
└── Abstract (already created)              : ~60 lines
────────────────────────────────────────────────────────
Total: ~845 lines vs 649 original (+30%)

BUT:
- DeploymentTasks: 649 → 120 lines (-81%!)
- Actions are focused, testable, reusable
- Services are reusable across codebase
- Much better organization
```

### Complexity Reduction

```
DeploymentTasks complexity:
  Before: 15-20 (very high)
  After: 2-3 (very low)

Average action complexity: 3-5 (low)
Service complexity: 2-4 (low)
```

---

## 🎯 Usage Examples

### Orchestration in DeploymentTasks

```php
class DeploymentTasks
{
    public function deploy(): void
    {
        $this->deployer->task('deploy', function () {
            // 1. Prepare
            $releaseName = PrepareDeploymentAction::run($this->deployer);

            // 2. Sync code
            SyncCodeAction::run($this->deployer, $releaseName);

            // 3. Configure
            ConfigureReleaseAction::run($this->deployer, $releaseName);

            // 4. Optimize
            OptimizeApplicationAction::run($this->deployer, $releaseName);

            // 5. Activate
            ActivateReleaseAction::run($this->deployer, $releaseName);

            $this->writeln("🎉 Deployment completed successfully!");
        });
    }

    public function rollback(): void
    {
        $this->deployer->task('rollback', function () {
            RollbackDeploymentAction::run($this->deployer);
        });
    }
}
```

### Direct Action Usage

```php
// Prepare a release
$releaseName = PrepareDeploymentAction::run($deployer);

// Rollback
RollbackDeploymentAction::run($deployer);

// Optimize app independently
OptimizeApplicationAction::run($deployer, $currentRelease);
```

### Service Usage

```php
// Check if deployment is locked
if ($lockManager->isLocked()) {
    $info = $lockManager->getLockInfo();
    echo "Deployment locked by: {$info['user']}";
}

// Get release info
$releases = $releaseManager->getReleases();
$current = $releaseManager->getCurrentRelease();
```

---

## ✨ Benefits

### 1. Massive Simplification ✅
- DeploymentTasks: 649 → 120 lines (-81%)
- Clear, readable orchestration
- Easy to understand flow

### 2. Focused Actions ✅
- 6 actions, each with single purpose
- Average 54 lines per action
- Highly testable

### 3. Reusable Services ✅
- ReleaseManager for all release operations
- LockManager for deployment locking
- PermissionManager for permission handling
- SharedResourceLinker for linking resources

### 4. Easy Testing ✅
```php
// Test prepare action
$action = new PrepareDeploymentAction($deployer, $releaseManager, $lockManager);
$releaseName = $action->execute();

// Test rollback
$action = new RollbackDeploymentAction($deployer, $releaseManager);
$action->execute();
```

### 5. Flexible Composition ✅
```php
// Custom deployment flow
Pipeline::send($deployment)
    ->through([
        PrepareDeploymentAction::class,
        SyncCodeAction::class,
        ConfigureReleaseAction::class,
        // Skip optimize if not needed
        ActivateReleaseAction::class,
    ])
    ->thenReturn();
```

---

## 📝 Implementation Plan

### Phase 1: Services (Foundation)
1. Create ReleaseManager
2. Create LockManager
3. Create PermissionManager
4. Create SharedResourceLinker

### Phase 2: Actions (Core Logic)
1. Create PrepareDeploymentAction
2. Create SyncCodeAction
3. Create ConfigureReleaseAction
4. Create OptimizeApplicationAction
5. Create ActivateReleaseAction
6. Create RollbackDeploymentAction

### Phase 3: Refactor Task Class
1. Simplify DeploymentTasks to use actions
2. Keep backward compatibility
3. Test all flows

### Phase 4: Utilities
1. Create helpers/deployment.php
2. Add convenience functions

---

## 🎨 Summary

This strategy achieves:

✅ **6 focused actions** (vs 10-15 originally planned)
✅ **4 reusable services** (extracted complex logic)
✅ **81% reduction** in DeploymentTasks.php (649 → 120 lines)
✅ **Better organization** (actions, services, helpers)
✅ **High testability** (everything easily tested)
✅ **Maximum reusability** (services used anywhere)

**Result:** Clean, maintainable, Spatie-style architecture! 🎯
