# Failsafe Mechanisms & Recommendations

This document outlines the current failsafe mechanisms in Laravel Deployer and recommends the **5 most critical** additional safety features to implement.

## ✅ Currently Implemented Failsafe Mechanisms

### 1. **Zero-Downtime Deployment**
- Uses atomic symlink swapping (`mv -fT`)
- Creates new release before removing old one
- Current symlink always points to working release
- **Benefit**: No service interruption during deployment

### 2. **Deployment Locking**
- Creates `.dep/deploy.lock` file during deployment
- Prevents concurrent deployments
- Auto-unlocks on successful completion
- Shows lock information if deployment is locked
- **Benefit**: Prevents race conditions and corrupted deployments

### 3. **Release History Management**
- Maintains configurable number of releases (default: 3)
- Sorted by timestamp (newest first)
- Easy rollback to previous versions
- **Benefit**: Quick recovery from bad deployments

### 4. **Rollback Capability**
- **Command**: `php artisan deploy:rollback {environment}`
- Instant rollback to previous release
- Can rollback to specific release with `--release=` option
- Includes cache clearing and service restarts
- **Benefit**: Quick recovery mechanism

### 5. **Pre-Deployment Validation**
- Confirms deployment target before execution
- Extra warning for production deployments
- Can skip with `--no-confirm` flag
- **Benefit**: Prevents accidental deployments

### 6. **Shared Resources**
- Shared directories (storage, logs) persist across deployments
- Shared files (.env) maintained outside releases
- Symlinks created automatically
- **Benefit**: Data persistence and configuration stability

### 7. **Health Checks**
- Resource checks (disk space, memory)
- Endpoint health verification
- Configurable health check URLs
- **Benefit**: Ensures server is ready before/after deployment

### 8. **Graceful Error Handling**
- Try-catch blocks for critical operations
- Detailed error messages with context
- Unlock on failure to prevent lock starvation
- **Benefit**: Better debugging and recovery

### 9. **Service Management**
- Auto-detects PHP-FPM versions
- Gracefully handles service restart failures
- Continues deployment if non-critical services fail
- **Benefit**: Robust service management

### 10. **Notification System**
- Desktop notifications for success/failure
- Cross-platform support (macOS, Linux, Windows)
- Immediate feedback on deployment status
- **Benefit**: Real-time deployment awareness

---

## 🚀 Top 5 Recommended Additional Failsafe Mechanisms

These 5 mechanisms provide the highest impact for production safety and should be prioritized for implementation.

---

### 1. **Automatic Rollback on Failure** ⭐⭐⭐

**Priority**: CRITICAL  
**Complexity**: Medium  
**Impact**: Very High  
**Time to Implement**: 2-3 days

#### Why This Matters
Currently, when a deployment fails, you must manually rollback. This increases downtime and requires immediate human intervention. Automatic rollback minimizes downtime and provides instant recovery.

#### Implementation

```php
// In DeployCommand.php
protected function runDeploy(bool $noConfirm, bool $autoRollback = true): void
{
    $previousRelease = null;
    
    try {
        // Store current release before deployment
        $previousRelease = $deploymentTasks->getCurrentRelease();
        
        // Run all deployment steps
        $this->executeDeployment();
        
        // Verify deployment succeeded
        if (!$this->verifyDeployment()) {
            throw new DeploymentFailedException('Health checks failed');
        }
        
    } catch (\Exception $e) {
        if ($autoRollback && $previousRelease) {
            $this->warn('❌ Deployment failed: ' . $e->getMessage());
            $this->warn('🔄 Automatically rolling back to previous release...');
            
            try {
                $deploymentTasks->rollback($previousRelease);
                $this->info('✅ Rollback completed successfully');
                $this->error('Deployment failed and was rolled back');
            } catch (\Exception $rollbackError) {
                $this->error('❌ Rollback also failed: ' . $rollbackError->getMessage());
                $this->error('⚠️  MANUAL INTERVENTION REQUIRED');
            }
        }
        
        throw $e;
    }
}
```

#### Configuration

Add to `deploy.yaml`:
```yaml
production:
  auto_rollback: true
  rollback_on:
    - health_check_failure
    - migration_failure
    - smoke_test_failure
```

#### Features
- Automatic rollback on any deployment failure
- Configurable per environment
- `--no-auto-rollback` flag to disable for specific deployments
- Logs rollback actions for audit trail
- Attempts to notify on rollback

#### Benefits
- **Minimizes downtime** - Instant recovery without human intervention
- **Reduces stress** - Teams don't panic during failed deployments
- **24/7 deployments** - Safe to deploy outside business hours
- **Confidence** - Deploy knowing you have a safety net

---

### 2. **Database Migration Safety** ⭐⭐⭐

**Priority**: CRITICAL  
**Complexity**: High  
**Impact**: Very High (prevents data loss)  
**Time to Implement**: 3-5 days

#### Why This Matters
Database migrations are the most dangerous part of deployment. Schema changes can corrupt data, and rolling back code doesn't rollback the database. This is the #1 cause of deployment disasters.

#### Implementation

**Step 1: Pre-Migration Backup**

```php
// In DeploymentTasks.php
public function artisanMigrate(): void
{
    $this->deployer->task('artisan:migrate', function ($deployer) {
        $releasePath = $deployer->getReleasePath();
        $phpPath = "/usr/bin/php";
        
        // Check if there are pending migrations
        $deployer->writeln("🔍 Checking for pending migrations...");
        $status = $deployer->run("{$phpPath} {$releasePath}/artisan migrate:status");
        
        if (strpos($status, 'Pending') !== false) {
            // Create backup before running migrations
            $deployer->writeln("📦 Creating pre-migration database backup...");
            $databaseTasks = new DatabaseTasks($deployer);
            $databaseTasks->backup('pre-migration');
            
            $deployer->writeln("⚠️  Running migrations with pending changes...");
        }
        
        // Run migrations
        $deployer->writeln("run {$phpPath} {$releasePath}/artisan migrate --force");
        $result = $deployer->run("{$phpPath} {$releasePath}/artisan migrate --force");
        
        if (!empty($result)) {
            $lines = explode("\n", trim($result));
            foreach ($lines as $line) {
                $deployer->writeln($line);
            }
        }
        
        $deployer->writeln("✅ Migrations completed successfully");
    });
}
```

**Step 2: Migration Dry Run Command**

```php
// New command: MigrationPreviewCommand.php
class MigrationPreviewCommand extends Command
{
    protected $signature = 'deploy:preview-migrations {environment}';
    protected $description = 'Preview what migrations will run on deployment';
    
    public function handle(): int
    {
        // Show migration status
        // Show SQL that will be executed (--pretend)
        // Estimate backup size needed
        // Show rollback plan
    }
}
```

**Step 3: Migration Rollback Tracking**

Store migration metadata:
```json
// .dep/migrations/deployment_{timestamp}.json
{
  "deployment_id": "202501.5",
  "timestamp": "2025-01-08T10:30:00Z",
  "migrations_run": [
    "2025_01_08_100000_add_user_roles",
    "2025_01_08_103000_create_permissions_table"
  ],
  "backup_file": ".dep/backups/pre-migration-202501.5.sql.gz",
  "can_rollback": true,
  "rollback_steps": 2
}
```

#### Features
- Automatic backup before any migration
- Migration dry-run preview
- Track which migrations ran in each deployment
- Store rollback metadata
- Verify backup integrity before migrating

#### Configuration

Add to `deploy.yaml`:
```yaml
production:
  database:
    backup_before_migrate: true
    verify_backup: true
    migration_timeout: 1800
    require_migration_preview: true  # Require manual approval for migrations
```

#### Benefits
- **Zero data loss** - Always have a backup before schema changes
- **Confidence** - Know exactly what will change
- **Quick recovery** - Documented rollback steps
- **Compliance** - Audit trail of all schema changes

---

### 3. **Smoke Tests After Deployment** ⭐⭐⭐

**Priority**: HIGH  
**Complexity**: Medium  
**Impact**: High  
**Time to Implement**: 2-3 days

#### Why This Matters
Deployments can succeed technically but fail functionally. Code runs, services restart, but authentication is broken, payment processing fails, or APIs return errors. Smoke tests catch these issues immediately.

#### Implementation

```php
// New command: SmokeTestCommand.php
class SmokeTestCommand extends Command
{
    protected $signature = 'deploy:smoke-test {environment}';
    protected $description = 'Run smoke tests after deployment';
    
    public function handle(): int
    {
        $tests = [
            'Application Responds' => fn() => $this->testAppResponds(),
            'Database Connected' => fn() => $this->testDatabaseConnection(),
            'Cache Working' => fn() => $this->testCache(),
            'Queue Processing' => fn() => $this->testQueue(),
            'Authentication Works' => fn() => $this->testAuth(),
            'API Endpoints' => fn() => $this->testCriticalEndpoints(),
        ];
        
        $passed = 0;
        $failed = 0;
        
        foreach ($tests as $name => $test) {
            $this->info("Testing: {$name}...");
            
            try {
                $result = $test();
                if ($result) {
                    $this->info("  ✓ {$name} - PASSED");
                    $passed++;
                } else {
                    $this->error("  ✗ {$name} - FAILED");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->error("  ✗ {$name} - ERROR: " . $e->getMessage());
                $failed++;
            }
        }
        
        $this->newLine();
        $this->info("Smoke Tests: {$passed} passed, {$failed} failed");
        
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
    
    protected function testAppResponds(): bool
    {
        $deployer = new Deployer($this->argument('environment'), $config);
        $currentPath = $config['deploy_path'] . '/current';
        
        // Test app returns 200
        $result = $deployer->run("cd {$currentPath} && php artisan tinker --execute=\"echo 'OK';\"");
        return trim($result) === 'OK';
    }
    
    protected function testDatabaseConnection(): bool
    {
        // Test database connection works
        $result = $deployer->run("cd {$currentPath} && php artisan tinker --execute=\"DB::connection()->getPdo(); echo 'OK';\"");
        return strpos($result, 'OK') !== false;
    }
    
    protected function testCache(): bool
    {
        // Test cache set/get
        $testKey = 'smoke_test_' . time();
        $deployer->run("cd {$currentPath} && php artisan cache:put {$testKey} test 60");
        $result = $deployer->run("cd {$currentPath} && php artisan cache:get {$testKey}");
        return trim($result) === 'test';
    }
    
    protected function testCriticalEndpoints(): bool
    {
        // Test critical API endpoints
        $endpoints = config('deploy.smoke_test_endpoints', []);
        
        foreach ($endpoints as $endpoint) {
            $response = Http::get($endpoint);
            if (!$response->successful()) {
                return false;
            }
        }
        
        return true;
    }
}
```

#### Integration with Deployment

```php
// In DeployCommand.php - add after deployment
if ($config['smoke_tests_enabled'] ?? true) {
    $this->info('🧪 Running smoke tests...');
    
    $smokeTestResult = $this->call('deploy:smoke-test', [
        'environment' => $environment
    ]);
    
    if ($smokeTestResult !== 0) {
        if ($config['rollback_on_smoke_test_failure'] ?? true) {
            $this->error('❌ Smoke tests failed. Rolling back...');
            $this->call('deploy:rollback', [
                'environment' => $environment,
                '--no-confirm' => true
            ]);
        } else {
            $this->error('❌ Smoke tests failed but auto-rollback is disabled');
        }
        
        return self::FAILURE;
    }
}
```

#### Configuration

```yaml
production:
  smoke_tests_enabled: true
  rollback_on_smoke_test_failure: true
  smoke_test_endpoints:
    - https://api.yourapp.com/health
    - https://yourapp.com/api/v1/status
    - https://yourapp.com/login
  smoke_test_timeout: 300
```

#### Benefits
- **Catch functional failures** - Not just technical success
- **Immediate feedback** - Know if deployment actually works
- **Prevent user impact** - Rollback before users notice
- **Confidence score** - Quantify deployment health

---

### 4. **Enhanced Health Checks** ⭐⭐

**Priority**: HIGH  
**Complexity**: Low  
**Impact**: High  
**Time to Implement**: 1-2 days

#### Why This Matters
Current health checks are basic. Enhanced checks verify the application is truly ready to serve traffic, not just that the server is alive.

#### Implementation

```php
// Enhance HealthCheckTasks.php
public function checkPostDeployment(): void
{
    $this->deployer->writeln("🏥 Running enhanced health checks...");
    
    $checks = [
        'disk_space' => $this->checkDiskSpace(),
        'memory' => $this->checkMemory(),
        'database' => $this->checkDatabase(),
        'cache' => $this->checkCache(),
        'queue' => $this->checkQueue(),
        'scheduler' => $this->checkScheduler(),
        'endpoints' => $this->checkEndpoints(),
        'dependencies' => $this->checkDependencies(),
    ];
    
    $failed = array_filter($checks, fn($result) => !$result);
    
    if (!empty($failed)) {
        $this->deployer->writeln("❌ Health checks failed: " . implode(', ', array_keys($failed)), 'error');
        throw new HealthCheckException('Deployment health checks failed');
    }
    
    $this->deployer->writeln("✅ All health checks passed");
}

protected function checkDatabase(): bool
{
    try {
        $currentPath = $this->deployer->getCurrentPath();
        
        // Test connection
        $result = $this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"DB::connection()->getPdo(); echo 'OK';\"");
        
        if (strpos($result, 'OK') === false) {
            $this->deployer->writeln("  ✗ Database connection failed", 'error');
            return false;
        }
        
        // Test query
        $result = $this->deployer->run("cd {$currentPath} && php artisan tinker --execute=\"DB::table('migrations')->count(); echo 'OK';\"");
        
        if (strpos($result, 'OK') === false) {
            $this->deployer->writeln("  ✗ Database query failed", 'error');
            return false;
        }
        
        $this->deployer->writeln("  ✓ Database healthy");
        return true;
        
    } catch (\Exception $e) {
        $this->deployer->writeln("  ✗ Database check failed: " . $e->getMessage(), 'error');
        return false;
    }
}

protected function checkQueue(): bool
{
    try {
        $currentPath = $this->deployer->getCurrentPath();
        
        // Check queue workers are running
        $result = $this->deployer->run("ps aux | grep 'queue:work' | grep -v grep | wc -l");
        $workerCount = (int)trim($result);
        
        if ($workerCount === 0) {
            $this->deployer->writeln("  ⚠ No queue workers running", 'comment');
            return true; // Warning but not fatal
        }
        
        $this->deployer->writeln("  ✓ Queue workers active ({$workerCount} workers)");
        return true;
        
    } catch (\Exception $e) {
        $this->deployer->writeln("  ⚠ Queue check failed: " . $e->getMessage(), 'comment');
        return true; // Warning but not fatal
    }
}

protected function checkScheduler(): bool
{
    try {
        $currentPath = $this->deployer->getCurrentPath();
        
        // Check scheduler is configured
        $result = $this->deployer->run("crontab -l | grep 'artisan schedule:run' | grep -v grep | wc -l");
        $cronCount = (int)trim($result);
        
        if ($cronCount === 0) {
            $this->deployer->writeln("  ⚠ Scheduler not configured in crontab", 'comment');
            return true; // Warning but not fatal
        }
        
        $this->deployer->writeln("  ✓ Scheduler configured");
        return true;
        
    } catch (\Exception $e) {
        $this->deployer->writeln("  ⚠ Scheduler check failed: " . $e->getMessage(), 'comment');
        return true; // Warning but not fatal
    }
}

protected function checkDependencies(): bool
{
    try {
        $currentPath = $this->deployer->getCurrentPath();
        
        // Check composer dependencies installed
        if (!$this->deployer->test("[ -d {$currentPath}/vendor ]")) {
            $this->deployer->writeln("  ✗ Vendor directory missing", 'error');
            return false;
        }
        
        // Check node_modules if using assets
        if ($this->deployer->test("[ -f {$currentPath}/package.json ]")) {
            if (!$this->deployer->test("[ -d {$currentPath}/node_modules ]")) {
                $this->deployer->writeln("  ⚠ Node modules missing", 'comment');
            }
        }
        
        $this->deployer->writeln("  ✓ Dependencies installed");
        return true;
        
    } catch (\Exception $e) {
        $this->deployer->writeln("  ✗ Dependency check failed: " . $e->getMessage(), 'error');
        return false;
    }
}
```

#### Configuration

```yaml
production:
  health_checks:
    enabled: true
    fail_on_error: true
    checks:
      - disk_space
      - memory
      - database
      - cache
      - queue
      - scheduler
      - endpoints
      - dependencies
    endpoints:
      - https://api.yourapp.com/health
      - https://yourapp.com/api/v1/status
    endpoint_timeout: 30
    endpoint_retries: 3
```

#### Benefits
- **Comprehensive verification** - Full stack health check
- **Early problem detection** - Find issues before users do
- **Actionable feedback** - Know exactly what's wrong
- **Customizable** - Enable/disable checks per environment

---

### 5. **Deployment Logs** ⭐⭐

**Priority**: HIGH  
**Complexity**: Low  
**Impact**: Medium-High  
**Time to Implement**: 1 day

#### Why This Matters
Console output disappears. When debugging a failed deployment from last week, you need logs. For compliance, you need an audit trail. Deployment logs are essential for troubleshooting and accountability.

#### Implementation

```php
// New class: DeploymentLogger.php
class DeploymentLogger
{
    protected string $logPath;
    protected string $deploymentId;
    protected float $startTime;
    
    public function __construct(string $environment)
    {
        $this->deploymentId = date('Ymd_His') . '_' . uniqid();
        $this->logPath = base_path(".dep/logs/deployment_{$this->deploymentId}.log");
        $this->startTime = microtime(true);
        
        // Ensure log directory exists
        if (!is_dir(dirname($this->logPath))) {
            mkdir(dirname($this->logPath), 0755, true);
        }
        
        $this->logHeader($environment);
    }
    
    protected function logHeader(string $environment): void
    {
        $header = <<<LOG
========================================
Deployment Log
========================================
Deployment ID: {$this->deploymentId}
Environment: {$environment}
Started: {$this->formatTime($this->startTime)}
User: {$this->getUser()}
Git Branch: {$this->getGitBranch()}
Git Commit: {$this->getGitCommit()}
========================================

LOG;
        file_put_contents($this->logPath, $header);
    }
    
    public function log(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $elapsed = round(microtime(true) - $this->startTime, 2);
        
        $logLine = "[{$timestamp}] [{$level}] [+{$elapsed}s] {$message}\n";
        
        file_put_contents($this->logPath, $logLine, FILE_APPEND);
    }
    
    public function logCommand(string $command, string $output, int $exitCode): void
    {
        $this->log("Command: {$command}", 'CMD');
        if (!empty($output)) {
            $this->log("Output:\n" . $output, 'OUT');
        }
        $this->log("Exit Code: {$exitCode}", $exitCode === 0 ? 'INFO' : 'ERROR');
    }
    
    public function logError(\Exception $e): void
    {
        $this->log("ERROR: " . $e->getMessage(), 'ERROR');
        $this->log("Stack Trace:\n" . $e->getTraceAsString(), 'ERROR');
    }
    
    public function logSuccess(): void
    {
        $duration = round(microtime(true) - $this->startTime, 2);
        
        $footer = <<<LOG

========================================
Deployment Completed Successfully
Duration: {$duration}s
Finished: {$this->formatTime(microtime(true))}
========================================
LOG;
        file_put_contents($this->logPath, $footer, FILE_APPEND);
    }
    
    public function logFailure(\Exception $e): void
    {
        $duration = round(microtime(true) - $this->startTime, 2);
        
        $footer = <<<LOG

========================================
Deployment Failed
Duration: {$duration}s
Error: {$e->getMessage()}
Finished: {$this->formatTime(microtime(true))}
========================================
LOG;
        file_put_contents($this->logPath, $footer, FILE_APPEND);
    }
    
    public function getLogPath(): string
    {
        return $this->logPath;
    }
}
```

#### Integration

```php
// In DeployCommand.php
protected function runDeploy(bool $noConfirm): void
{
    $logger = new DeploymentLogger($environment);
    
    try {
        $logger->log("Starting deployment to {$environment}");
        
        // Load configuration
        $logger->log("Loading configuration from deploy.yaml");
        $config = Yaml::parseFile($deployYamlPath);
        
        // Execute deployment steps
        foreach ($deploymentSteps as $step => $task) {
            $logger->log("Executing: {$step}");
            $task->execute();
            $logger->log("Completed: {$step}");
        }
        
        $logger->logSuccess();
        $this->info("Deployment log: {$logger->getLogPath()}");
        
    } catch (\Exception $e) {
        $logger->logError($e);
        $logger->logFailure($e);
        $this->error("Deployment log: {$logger->getLogPath()}");
        throw $e;
    }
}
```

#### Log Rotation

```php
// Clean old logs (keep last 30 days)
protected function rotateDeploymentLogs(): void
{
    $logsPath = base_path('.dep/logs');
    $files = glob($logsPath . '/deployment_*.log');
    
    $thirtyDaysAgo = time() - (30 * 24 * 60 * 60);
    
    foreach ($files as $file) {
        if (filemtime($file) < $thirtyDaysAgo) {
            unlink($file);
        }
    }
}
```

#### Configuration

```yaml
production:
  logging:
    enabled: true
    retention_days: 30
    log_path: .dep/logs
    include_output: true
    compress_old_logs: true
```

#### Benefits
- **Historical record** - Debug deployments from weeks ago
- **Audit trail** - Who deployed what and when
- **Troubleshooting** - Full output of all commands
- **Compliance** - Required for many industries
- **Performance tracking** - How long each step takes

---

## Implementation Priority & Timeline

### Week 1: Foundation
**Day 1-2**: Deployment Logs  
**Day 3-5**: Enhanced Health Checks

### Week 2: Safety Net
**Day 6-8**: Smoke Tests  
**Day 9-10**: Integrate Smoke Tests with Deployment

### Week 3-4: Critical Protection
**Day 11-15**: Database Migration Safety  
**Day 16-18**: Automatic Rollback on Failure  
**Day 19-20**: Integration Testing

### Total Timeline: 3-4 weeks for all 5 mechanisms

---

## Testing the Failsafe Mechanisms

### Test Automatic Rollback
```bash
# Introduce intentional failure in health check
# Deployment should auto-rollback
php artisan deploy staging
```

### Test Database Migration Safety
```bash
# Preview migrations before deploying
php artisan deploy:preview-migrations staging

# Deploy with migrations - should create backup first
php artisan deploy staging
```

### Test Smoke Tests
```bash
# Run smoke tests manually
php artisan deploy:smoke-test staging

# Deploy - smoke tests run automatically
php artisan deploy staging
```

### Test Enhanced Health Checks
```bash
# Health checks run as part of deployment
# Should catch database, cache, queue issues
php artisan deploy staging
```

### Test Deployment Logs
```bash
# Deploy and check log was created
php artisan deploy staging
ls -la .dep/logs/

# View deployment log
cat .dep/logs/deployment_20250108_103000_abc123.log
```

---

## Success Metrics

Track these metrics to measure the impact of failsafe mechanisms:

1. **Mean Time To Recovery (MTTR)**
   - Before: 15-30 minutes (manual rollback)
   - After: <2 minutes (automatic rollback)

2. **Failed Deployment Detection**
   - Before: Discovered by users
   - After: Caught by smoke tests/health checks

3. **Database Incident Rate**
   - Before: 1-2 per quarter
   - After: 0 (prevented by migration safety)

4. **Deployment Confidence**
   - Before: Only deploy during business hours
   - After: Deploy anytime with confidence

5. **Debugging Time**
   - Before: 1-2 hours to understand what happened
   - After: 5-10 minutes with deployment logs

---

## Conclusion

These 5 failsafe mechanisms provide the highest return on investment for deployment safety:

1. **Automatic Rollback** - Instant recovery from failures
2. **Database Migration Safety** - Prevents data loss
3. **Smoke Tests** - Validates functionality
4. **Enhanced Health Checks** - Comprehensive verification
5. **Deployment Logs** - Essential debugging and audit trail

Implementing all 5 transforms deployments from a risky, stressful event into a safe, routine operation. The total investment of 3-4 weeks pays off immediately with reduced downtime, prevented incidents, and team confidence.

**Start with Deployment Logs (1 day) to build the foundation, then add each mechanism incrementally.**
