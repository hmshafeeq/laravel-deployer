# Laravel Deployer Refactoring Recommendations

## Overview
This document outlines recommendations for simplifying and improving the Laravel Deployer package following Spatie's best practices.

## Current State Analysis

### Strengths ✅
- Well-organized action-based architecture
- Good separation of concerns with Services
- Solid base command class with common functionality
- Proper use of Spatie SSH package
- Comprehensive deployment workflow

### Areas for Improvement 🔧

## 1. Configuration Management

### Issue
Missing a published configuration file - Spatie packages typically expose configuration options.

### Recommendation
```php
// config/laravel-deployer.php
return [
    'timeout' => env('DEPLOYER_TIMEOUT', 900),
    'keep_releases' => env('DEPLOYER_KEEP_RELEASES', 5),
    'notifications' => [
        'enabled' => env('DEPLOYER_NOTIFICATIONS_ENABLED', false),
        'channels' => ['slack', 'mail'],
    ],
    'health_checks' => [
        'enabled' => true,
        'disk_threshold' => 90,
        'memory_threshold' => 90,
    ],
];
```

**Benefits**:
- Centralized configuration
- Environment-specific overrides
- Follows Laravel conventions

---

## 2. Command Simplification

### Issue: DeployCommand is Too Large (369 lines)

The `DeployCommand` class handles too many responsibilities:
- Environment validation
- Pre-deployment checks
- Deployment orchestration
- Health checks
- Service restarts
- Application URL fetching

### Recommendation
Extract logic into dedicated services:

```php
// Services/DeploymentOrchestrator.php
class DeploymentOrchestrator
{
    public function deploy(Deployer $deployer): void
    {
        $this->runHealthChecks();
        $this->runDeploymentPhases();
        $this->runPostDeploymentPhases();
    }
}

// Services/ViteDetector.php
class ViteDetector
{
    public function isRunning(): bool { ... }
}

// Services/ServiceRestarter.php
class ServiceRestarter
{
    public function restartAll(Deployer $deployer): void
    {
        RestartPhpFpmAction::run($deployer);
        RestartNginxAction::run($deployer);
        ReloadSupervisorAction::run($deployer);
    }
}
```

**Benefits**:
- Single Responsibility Principle
- Easier testing
- Reusable components
- Cleaner command class

---

## 3. Consistency Issues

### Issue: Mixed Terminology
- Some commands use `environment` argument
- Database commands use `server` argument
- Inconsistent naming causes confusion

### Recommendation
Standardize on `environment` across all commands:

```php
// Before
protected $signature = 'database:backup {server? : Server name}';

// After
protected $signature = 'database:backup {environment? : Environment name}';
```

**Benefits**:
- Consistent API
- Easier documentation
- Better DX

---

## 4. Abstract Classes Simplification

### Issue
`DeploymentAction`, `ServiceAction`, etc. exist only for semantic clarity but add no functionality.

### Recommendation
Remove intermediate abstract classes or add meaningful functionality:

```php
// Option 1: Remove them entirely and use Action directly

// Option 2: Add category-specific behavior
abstract class DeploymentAction extends Action
{
    protected function requiresLock(): bool { return true; }
    protected function supportsRollback(): bool { return true; }
}
```

**Benefits**:
- Reduced complexity
- Less inheritance depth
- Clearer purpose

---

## 5. Service Restart Logic

### Issue
Service restart logic is duplicated across:
- `DeployCommand::restartServices()`
- `RollbackCommand::performRollback()`
- Multiple actions

### Recommendation
Consolidate into a single `ServiceRestarter` class:

```php
class ServiceRestarter
{
    public function __construct(protected Deployer $deployer) {}

    public function restart(array $services = ['php-fpm', 'nginx', 'supervisor']): void
    {
        foreach ($services as $service) {
            match($service) {
                'php-fpm' => RestartPhpFpmAction::run($this->deployer),
                'nginx' => RestartNginxAction::run($this->deployer),
                'supervisor' => ReloadSupervisorAction::run($this->deployer),
            };
        }
    }
}
```

**Benefits**:
- DRY principle
- Centralized service management
- Configurable service list

---

## 6. Health Check Orchestration

### Issue
Health check logic scattered across DeployCommand:
- `runHealthChecks()` - pre-deployment
- `runApplicationHealthChecks()` - post-deployment
- `getApplicationUrl()` - uses slow tinker command

### Recommendation
Create dedicated `HealthCheckService`:

```php
class HealthCheckService
{
    public function runPreDeployment(Deployer $deployer): void
    {
        CheckDiskSpaceAction::run($deployer);
        CheckMemoryUsageAction::run($deployer);
    }

    public function runPostDeployment(Deployer $deployer): void
    {
        $appUrl = $deployer->get('app_url') ?: $this->detectAppUrl($deployer);
        CheckHealthEndpointAction::run($deployer, null, $appUrl);
        RunSmokeTestsAction::run($deployer, $appUrl);
    }

    protected function detectAppUrl(Deployer $deployer): string
    {
        // Use faster method than tinker
        return $deployer->run("cd {$deployer->getCurrentPath()} && grep APP_URL .env | cut -d '=' -f2");
    }
}
```

**Benefits**:
- Faster URL detection (no tinker overhead)
- Reusable health check logic
- Better separation of concerns

---

## 7. Action Verbosity

### Issue
Some actions have excessive `writeln()` calls, cluttering the codebase.

### Recommendation
Use event-driven logging or reduce verbosity:

```php
// Before (PrepareDeploymentAction)
$this->writeln("run [ -d {$deployPath} ] || mkdir -p {$deployPath};");
$this->run("[ -d {$deployPath} ] || mkdir -p {$deployPath}");

// After - batch operations
$this->runQuietly([
    "[ -d {$deployPath} ] || mkdir -p {$deployPath}",
    "cd {$deployPath}",
    "[ -d .dep ] || mkdir .dep",
    "[ -d releases ] || mkdir releases",
    "[ -d shared ] || mkdir shared",
]);
```

**Benefits**:
- Cleaner code
- Faster execution
- Optional verbosity flag

---

## 8. Error Handling Consistency

### Issue
Some commands use `executeWithErrorHandling()`, others implement custom try-catch.

### Recommendation
Standardize error handling across all commands:

```php
// All commands should use:
return $this->executeWithErrorHandling(
    fn () => $this->performOperation(),
    'Success message',
    'Error message'
);
```

**Benefits**:
- Consistent error messages
- Centralized exception handling
- Cleaner command code

---

## 9. Database Command Improvements

### Issue
Database commands have inconsistent patterns and mixed concerns.

### Recommendation
Consolidate into a single `database` command with subcommands:

```php
php artisan database backup staging
php artisan database download staging
php artisan database restore backup-file.sql
php artisan database upload backup-file.sql staging
```

Or use command groups:
```php
protected $signature = 'database:backup {environment}';
protected $signature = 'database:download {environment}';
protected $signature = 'database:restore {backup}';
protected $signature = 'database:upload {backup} {environment}';
```

**Benefits**:
- Clearer command structure
- Consistent naming
- Better discoverability

---

## 10. Dependency Injection

### Issue
Manual service instantiation throughout codebase:

```php
$lockManager = new LockManager($deployer);
$releaseManager = new ReleaseManager($deployer);
```

### Recommendation
Use constructor injection where possible:

```php
public function __construct(
    protected Deployer $deployer,
    protected ?LockManager $lockManager = null,
    protected ?ReleaseManager $releaseManager = null
) {
    $this->lockManager ??= new LockManager($deployer);
    $this->releaseManager ??= new ReleaseManager($deployer);
}
```

Current code already does this in some places - apply consistently.

**Benefits**:
- Easier testing
- Better testability with mocks
- Laravel container integration

---

## Implementation Priority

### Phase 1: Quick Wins (Low Risk, High Impact)
1. ✅ Add configuration file
2. ✅ Standardize command terminology (environment vs server)
3. ✅ Consolidate service restart logic
4. ✅ Improve health check URL detection

### Phase 2: Refactoring (Medium Risk, High Impact)
5. Extract DeployCommand logic to services
6. Reduce action verbosity
7. Standardize error handling

### Phase 3: Architectural (Higher Risk, Long Term)
8. Simplify abstract class hierarchy
9. Improve dependency injection patterns
10. Add event system for deployment hooks

---

## Spatie Package Best Practices Checklist

- [ ] Config file published to `config/` directory
- [ ] Consistent command naming (verb:noun pattern)
- [ ] Comprehensive docblocks with type hints
- [ ] Single responsibility per class
- [ ] Service classes for business logic
- [ ] Actions for single operations
- [ ] Proper exception handling
- [ ] Testable architecture (DI, interfaces)
- [ ] README with clear usage examples
- [ ] CHANGELOG following Keep a Changelog format

---

## Code Quality Metrics

### Current
- Average class complexity: Medium
- Command class sizes: 50-369 lines
- Test coverage: Partial
- Duplicate code: Low-Medium

### Target
- Average class complexity: Low
- Command class sizes: <150 lines
- Test coverage: >80%
- Duplicate code: Minimal

---

## Next Steps

1. Review and discuss recommendations with team
2. Prioritize changes based on impact/effort
3. Implement Phase 1 changes
4. Write/update tests
5. Update documentation
6. Release new version with CHANGELOG

---

## Additional Resources

- [Spatie Package Development Guidelines](https://spatie.be/docs/package-tools)
- [Laravel Best Practices](https://github.com/alexeymezenin/laravel-best-practices)
- [PHP Package Development Standards](https://www.php-fig.org/psr/)
