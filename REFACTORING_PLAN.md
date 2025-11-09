# Laravel Deployer - Refactoring Plan

> **Goal**: Improve code cleanliness, readability, and maintainability without changing functionality
>
> **Standards**: Spatie code styles, SOLID principles, DRY (Don't Repeat Yourself)

---

## 🔍 Identified Code Smells & Technical Debt

### 1. Violation of Single Responsibility Principle (SRP)

**`Deployer.php` (333 lines)** has too many responsibilities:
- SSH connection management
- Command execution (local & remote)
- Logging/output handling
- Environment variable loading
- Rsync operations
- Configuration management
- Release name generation

**Impact**: Hard to test, difficult to maintain, changes affect multiple concerns

---

### 2. Excessive Code Duplication (DRY Violations)

#### 2.1 Artisan Commands in `DeploymentTasks.php` (Lines 499-626)

**Problem**: 8 similar artisan methods with identical patterns:

```php
public function artisanConfigCache(): void {
    $releasePath = $this->deployer->getReleasePath();
    $phpPath = "/usr/bin/php";
    $this->deployer->writeln("run {$phpPath} {$releasePath}/artisan config:cache");
    $result = $this->deployer->run("{$phpPath} {$releasePath}/artisan config:cache");
    if (!empty($result)) {
        $lines = explode("\n", trim($result));
        foreach ($lines as $line) {
            $deployer->writeln($line);
        }
    }
}
```

**Duplicated in**: `artisanStorageLink()`, `artisanViewCache()`, `artisanRouteCache()`, `artisanOptimize()`, `artisanMigrate()`, `artisanQueueRestart()`

**Impact**: ~150 lines of duplicated code

---

#### 2.2 Configuration Loading

**Duplicated across**:
- `DeployCommand.php:96-121` (26 lines)
- `DatabaseBackupCommand.php:63-85` (23 lines)
- `RollbackCommand.php:25-44` (20 lines)

**Problem**: Same logic repeated 3+ times

```php
// Pattern repeated in each command
$yamlPath = base_path('deploy.yaml');
if (!file_exists($yamlPath)) {
    throw new \RuntimeException("Configuration file not found: {$yamlPath}");
}
$yaml = Yaml::parseFile($yamlPath);
$hostConfig = $yaml['hosts'][$environment] ?? [];
$this->config = [
    'environment' => $environment,
    'hostname' => $hostConfig['hostname'] ?? 'localhost',
    // ... more config
];
```

**Impact**: Inconsistency risk, harder to maintain

---

#### 2.3 Output Processing Pattern

**Repeated throughout codebase** (40+ occurrences):

```php
if (!empty($result)) {
    $lines = explode("\n", trim($result));
    foreach ($lines as $line) {
        $deployer->writeln($line);
    }
}
```

**Impact**: Boilerplate code, harder to read

---

### 3. Hard-coded Values & Magic Strings

**Found in multiple files**:

```php
// Hard-coded PHP path
$phpPath = "/usr/bin/php";  // Lines 503, 524, 540, 557, 574, 591, 614

// Magic directory names
".dep/deploy.lock"
"releases/"
"shared/"
"current"

// HTTP status codes as strings
if ($healthStatusCode === '200') // Should be integer or constant
if (!in_array($response, ['200', '302', '401']))
```

**Impact**: Hard to configure, brittle code, no single source of truth

---

### 4. No Verbosity Support

**Problem**: Console commands don't respect Laravel's verbosity flags

```php
// Currently ALL output is always displayed
$deployer->writeln("run cd {$deployPath}");
$deployer->writeln("run mkdir -p {$deployPath}");
// ... hundreds of lines of output
```

**Missing**:
- `-v`, `-vv`, `-vvv` flag support
- `--quiet` flag support
- No distinction between important messages and debug info

**Impact**: Poor user experience, cluttered output, can't debug effectively

---

### 5. Lack of Proper Abstractions

**Missing abstractions**:

```
❌ No Logger/OutputHandler class
❌ No ConfigurationService/Repository
❌ No SSHConnectionManager abstraction
❌ No Value Objects (DTOs) for configuration
❌ No CommandExecutor abstraction
❌ No ReleaseManager service
```

**Current state**: Everything tightly coupled to `Deployer` class

**Impact**: Hard to test, hard to extend, tight coupling

---

### 6. Poor Error Handling

**Problems**:

```php
// Generic exceptions everywhere
throw new \RuntimeException("Deployment is locked");
throw new \RuntimeException("Health endpoint failed");
throw new \RuntimeException("Rsync failed: ...");

// No exception hierarchy
// No domain-specific exceptions
// Error messages mixed with business logic
```

**Impact**: Hard to catch specific errors, poor error recovery

---

### 7. Missing Type Safety

**Problems**:

```php
// Configuration as raw arrays
protected array $config;  // What structure? What keys?

// Release names as plain strings
protected string $releaseName;  // No validation

// No enums for statuses
$status = 'pending';  // Could be typo: 'peding'
$environment = 'production';  // Could be: 'prod', 'Production', etc.
```

**Impact**: Runtime errors, hard to refactor, no IDE support

---

### 8. Tight Coupling

**Problems**:

```php
// Commands directly instantiate task classes
$deploymentTasks = new DeploymentTasks($this->deployer);
$healthCheckTasks = new HealthCheckTasks($this->deployer);
$serviceTasks = new ServiceTasks($this->deployer);

// No dependency injection
// No interfaces
// Hard to mock in tests
```

**Impact**: Hard to test, hard to extend, violates DIP

---

### 9. Inconsistent Patterns

**Problems**:

```php
// Mix of procedural and OOP
$deployer->task('deploy:info', function ($deployer) {
    // Closure-based
});

// vs

public function deployInfo(): void {
    // Method-based
}

// Inconsistent naming
public function artisanStorageLink()  // artisan prefix
public function restartPhpFpm()       // no service prefix
public function checkResources()      // no health prefix
```

**Impact**: Confusing code, hard to predict patterns

---

## 🎯 Proposed Refactoring Plan

### Phase 1: Extract Services & Abstractions ⭐ HIGH PRIORITY

#### 1.1 Create Value Objects (DTOs)

**New files**:

```
src/Data/
├── DeploymentConfig.php       # Configuration DTO with validation
├── ReleaseInfo.php            # Release metadata (name, timestamp, user)
├── ServerConnection.php       # SSH connection details
└── TaskResult.php             # Task execution result with status
```

**Example - `DeploymentConfig.php`**:

```php
<?php

namespace Shaf\LaravelDeployer\Data;

use Shaf\LaravelDeployer\Enums\Environment;

readonly class DeploymentConfig
{
    public function __construct(
        public Environment $environment,
        public string $hostname,
        public string $remoteUser,
        public string $deployPath,
        public string $branch,
        public string $composerOptions,
        public int $keepReleases = 3,
        public bool $isLocal = false,
        public string $application = 'Application',
        public array $rsyncExcludes = [],
        public array $rsyncIncludes = [],
    ) {}

    public static function fromArray(string $environment, array $config): self
    {
        return new self(
            environment: Environment::from($environment),
            hostname: $config['hostname'] ?? 'localhost',
            remoteUser: $config['remote_user'] ?? 'deploy',
            deployPath: $config['deploy_path'] ?? '/var/www/app',
            branch: $config['branch'] ?? 'main',
            composerOptions: $config['composer_options'] ?? '--no-dev --optimize-autoloader',
            keepReleases: $config['keep_releases'] ?? 3,
            isLocal: $config['local'] ?? false,
            application: $config['application'] ?? 'Application',
        );
    }
}
```

**Benefits**:
- ✅ Type safety
- ✅ Immutability with `readonly`
- ✅ IDE autocomplete
- ✅ Validation in one place
- ✅ Easy to test

---

#### 1.2 Extract Output/Logging Service

**New files**:

```
src/Services/
├── OutputService.php          # Handle all console output
└── Enums/VerbosityLevel.php   # Enum for verbosity levels
```

**Example - `OutputService.php`**:

```php
<?php

namespace Shaf\LaravelDeployer\Services;

use Symfony\Component\Console\Output\OutputInterface;
use Shaf\LaravelDeployer\Enums\VerbosityLevel;

class OutputService
{
    public function __construct(
        private OutputInterface $output,
        private string $prefix = '',
    ) {}

    public function info(string $message, VerbosityLevel $level = VerbosityLevel::NORMAL): void
    {
        $this->write($message, 'info', $level);
    }

    public function error(string $message): void
    {
        $this->write($message, 'error', VerbosityLevel::QUIET);
    }

    public function debug(string $message): void
    {
        $this->write($message, 'comment', VerbosityLevel::VERBOSE);
    }

    public function command(string $command): void
    {
        if ($this->output->isVerbose()) {
            $this->write("run {$command}", 'comment', VerbosityLevel::VERBOSE);
        }
    }

    public function commandOutput(string $output): void
    {
        if ($this->output->isVeryVerbose()) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                $this->write($line, 'plain', VerbosityLevel::VERY_VERBOSE);
            }
        }
    }

    private function write(string $message, string $style, VerbosityLevel $level): void
    {
        if (!$this->shouldDisplay($level)) {
            return;
        }

        $formatted = match($style) {
            'info' => "<info>{$this->prefix} {$message}</info>",
            'error' => "<error>{$this->prefix} {$message}</error>",
            'comment' => "<comment>{$this->prefix} {$message}</comment>",
            default => "{$this->prefix} {$message}",
        };

        $this->output->writeln($formatted);
    }

    private function shouldDisplay(VerbosityLevel $level): bool
    {
        return match($level) {
            VerbosityLevel::QUIET => true,
            VerbosityLevel::NORMAL => $this->output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL,
            VerbosityLevel::VERBOSE => $this->output->isVerbose(),
            VerbosityLevel::VERY_VERBOSE => $this->output->isVeryVerbose(),
            VerbosityLevel::DEBUG => $this->output->isDebug(),
        };
    }
}
```

**Example - `VerbosityLevel.php`**:

```php
<?php

namespace Shaf\LaravelDeployer\Enums;

enum VerbosityLevel: string
{
    case QUIET = 'quiet';           // Only errors
    case NORMAL = 'normal';         // Important messages
    case VERBOSE = 'verbose';       // -v: Commands being run
    case VERY_VERBOSE = 'very_verbose'; // -vv: Command output
    case DEBUG = 'debug';           // -vvv: Everything
}
```

**Benefits**:
- ✅ Respect Laravel verbosity flags
- ✅ Centralized output logic
- ✅ Easy to test
- ✅ Consistent formatting

**Usage**:

```php
// Before
$deployer->writeln("run cd {$deployPath}");

// After
$this->output->command("cd {$deployPath}");  // Only shows with -v
```

---

#### 1.3 Extract Configuration Service

**New file**: `src/Services/ConfigurationService.php`

```php
<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Exceptions\ConfigurationException;
use Symfony\Component\Yaml\Yaml;

class ConfigurationService
{
    public function __construct(
        private string $basePath,
    ) {}

    public function load(string $environment): DeploymentConfig
    {
        $yamlPath = $this->findConfigFile();
        $yaml = $this->parseYaml($yamlPath);

        $this->validateEnvironment($environment, $yaml);

        $hostConfig = $yaml['hosts'][$environment];
        $globalConfig = $yaml['config'] ?? [];

        // Merge with environment variables
        $config = $this->mergeWithEnvVars($environment, $hostConfig, $globalConfig);

        return DeploymentConfig::fromArray($environment, $config);
    }

    private function findConfigFile(): string
    {
        $locations = [
            $this->basePath . '/.deploy/deploy.yaml',
            $this->basePath . '/deploy.yaml',
        ];

        foreach ($locations as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new ConfigurationException(
            'Configuration file not found. Expected: deploy.yaml or .deploy/deploy.yaml'
        );
    }

    private function parseYaml(string $path): array
    {
        try {
            return Yaml::parseFile($path);
        } catch (\Exception $e) {
            throw new ConfigurationException(
                "Failed to parse configuration file: {$e->getMessage()}"
            );
        }
    }

    private function validateEnvironment(string $environment, array $yaml): void
    {
        if (!isset($yaml['hosts'][$environment])) {
            $available = implode(', ', array_keys($yaml['hosts'] ?? []));
            throw new ConfigurationException(
                "Environment '{$environment}' not found in deploy.yaml. Available: {$available}"
            );
        }
    }

    private function mergeWithEnvVars(string $environment, array $hostConfig, array $globalConfig): array
    {
        $this->loadEnvFile($environment);

        return array_merge(
            $hostConfig,
            $globalConfig,
            $this->getEnvOverrides()
        );
    }

    private function loadEnvFile(string $environment): void
    {
        $envFile = "{$this->basePath}/.deploy/.env.{$environment}";

        if (file_exists($envFile)) {
            $dotenv = \Dotenv\Dotenv::createImmutable(
                "{$this->basePath}/.deploy",
                ".env.{$environment}"
            );
            $dotenv->load();
        }
    }

    private function getEnvOverrides(): array
    {
        $prefix = 'DEPLOY_';
        $overrides = [];

        if ($host = $this->getEnv($prefix . 'HOST')) {
            $overrides['hostname'] = $host;
        }

        if ($user = $this->getEnv($prefix . 'USER')) {
            $overrides['remote_user'] = $user;
        }

        if ($path = $this->getEnv($prefix . 'PATH')) {
            $overrides['deploy_path'] = $path;
        }

        if ($branch = $this->getEnv($prefix . 'BRANCH')) {
            $overrides['branch'] = $branch;
        }

        return $overrides;
    }

    private function getEnv(string $key): ?string
    {
        return $_ENV[$key] ?? getenv($key) ?: null;
    }
}
```

**Benefits**:
- ✅ Single source for configuration
- ✅ Proper error messages
- ✅ Environment variable handling
- ✅ Validation in one place
- ✅ Easy to test

---

#### 1.4 Extract SSH/Command Execution Service

**New files**:

```
src/Contracts/
└── CommandExecutor.php        # Interface

src/Services/
├── RemoteCommandExecutor.php  # SSH execution
└── LocalCommandExecutor.php   # Local execution
```

**Example - `CommandExecutor.php`**:

```php
<?php

namespace Shaf\LaravelDeployer\Contracts;

interface CommandExecutor
{
    public function execute(string $command): string;

    public function test(string $condition): bool;

    public function isLocal(): bool;
}
```

**Example - `RemoteCommandExecutor.php`**:

```php
<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\ServerConnection;
use Shaf\LaravelDeployer\Exceptions\SSHConnectionException;
use Spatie\Ssh\Ssh;

class RemoteCommandExecutor implements CommandExecutor
{
    private Ssh $ssh;

    public function __construct(
        private ServerConnection $connection,
        private OutputService $output,
    ) {
        $this->ssh = Ssh::create($connection->user, $connection->host)
            ->disableStrictHostKeyChecking()
            ->disablePasswordAuthentication();

        if ($connection->port) {
            $this->ssh->usePort($connection->port);
        }
    }

    public function execute(string $command): string
    {
        $this->output->command($command);

        try {
            $result = $this->ssh->execute($command);
            $output = trim($result->getOutput());

            $this->output->commandOutput($output);

            return $output;
        } catch (\Exception $e) {
            throw new SSHConnectionException(
                "Remote command failed: {$command}\n{$e->getMessage()}"
            );
        }
    }

    public function test(string $condition): bool
    {
        $result = $this->ssh->execute($condition . ' && echo "true" || echo "false"');
        return trim($result->getOutput()) === 'true';
    }

    public function isLocal(): bool
    {
        return false;
    }
}
```

**Example - `LocalCommandExecutor.php`**:

```php
<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Symfony\Component\Process\Process;

class LocalCommandExecutor implements CommandExecutor
{
    public function __construct(
        private OutputService $output,
        private string $workingDirectory,
        private int $timeout = 900,
    ) {}

    public function execute(string $command): string
    {
        $this->output->command($command);

        $process = Process::fromShellCommandline($command, $this->workingDirectory);
        $process->setTimeout($this->timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Command failed: {$command}\n{$process->getErrorOutput()}"
            );
        }

        $output = trim($process->getOutput());
        $this->output->commandOutput($output);

        return $output;
    }

    public function test(string $condition): bool
    {
        $process = Process::fromShellCommandline($condition, $this->workingDirectory);
        $process->run();
        return $process->isSuccessful();
    }

    public function isLocal(): bool
    {
        return true;
    }
}
```

**Benefits**:
- ✅ Strategy pattern for local vs remote
- ✅ Easy to test (mock interface)
- ✅ Separation of concerns
- ✅ Proper error handling

---

### Phase 2: Eliminate Code Duplication ⭐ HIGH PRIORITY

#### 2.1 Create ArtisanTaskRunner

**New file**: `src/Services/ArtisanTaskRunner.php`

```php
<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;

class ArtisanTaskRunner
{
    public function __construct(
        private CommandExecutor $executor,
        private OutputService $output,
        private string $releasePath,
        private string $phpBinary = '/usr/bin/php',
    ) {}

    public function run(string $command, array $options = [], bool $force = false): string
    {
        $optionsString = $this->buildOptionsString($options, $force);
        $fullCommand = "{$this->phpBinary} {$this->releasePath}/artisan {$command}{$optionsString}";

        $this->output->info("Running artisan {$command}");

        return $this->executor->execute($fullCommand);
    }

    private function buildOptionsString(array $options, bool $force): string
    {
        $parts = [];

        if ($force) {
            $parts[] = '--force';
        }

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

**Usage - Before**:

```php
public function artisanConfigCache(): void {
    $releasePath = $this->deployer->getReleasePath();
    $phpPath = "/usr/bin/php";
    $this->deployer->writeln("run {$phpPath} {$releasePath}/artisan config:cache");
    $result = $this->deployer->run("{$phpPath} {$releasePath}/artisan config:cache");
    // ... output handling
}

public function artisanViewCache(): void {
    $releasePath = $this->deployer->getReleasePath();
    $phpPath = "/usr/bin/php";
    $this->deployer->writeln("run {$phpPath} {$releasePath}/artisan view:cache");
    $result = $this->deployer->run("{$phpPath} {$releasePath}/artisan view:cache");
    // ... output handling
}

// ... 6 more similar methods
```

**Usage - After**:

```php
public function artisanConfigCache(): void
{
    $this->artisan->run('config:cache');
}

public function artisanViewCache(): void
{
    $this->artisan->run('view:cache');
}

public function artisanMigrate(): void
{
    $this->artisan->run('migrate', force: true);
}
```

**Code Reduction**: ~150 lines → ~30 lines (80% reduction!)

---

#### 2.2 Create BaseTaskRunner

**New file**: `src/Deployer/BaseTaskRunner.php`

```php
<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Services\OutputService;
use Shaf\LaravelDeployer\Data\DeploymentConfig;

abstract class BaseTaskRunner
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config,
    ) {}

    protected function task(string $name, callable $callback): void
    {
        $this->output->info("task {$name}");
        $callback($this);
    }

    protected function run(string $command): string
    {
        return $this->executor->execute($command);
    }

    protected function test(string $condition): bool
    {
        return $this->executor->test($condition);
    }

    protected function getDeployPath(): string
    {
        return $this->config->deployPath;
    }

    protected function getReleasePath(string $releaseName): string
    {
        return $this->config->deployPath . '/releases/' . $releaseName;
    }

    protected function getCurrentPath(): string
    {
        return $this->config->deployPath . '/current';
    }

    protected function getSharedPath(): string
    {
        return $this->config->deployPath . '/shared';
    }
}
```

**Usage**:

```php
class DeploymentTasks extends BaseTaskRunner
{
    // Now has access to all common methods
    // No need to pass $deployer around
}
```

**Benefits**:
- ✅ Consistent base for all task classes
- ✅ DRY - common methods in one place
- ✅ Proper dependency injection
- ✅ Easy to extend

---

#### 2.3 Extract Reusable Helpers

**New file**: `src/Concerns/ExecutesCommands.php`

```php
<?php

namespace Shaf\LaravelDeployer\Concerns;

trait ExecutesCommands
{
    protected function pathExists(string $path): bool
    {
        return $this->test("[ -e {$path} ]");
    }

    protected function directoryExists(string $path): bool
    {
        return $this->test("[ -d {$path} ]");
    }

    protected function fileExists(string $path): bool
    {
        return $this->test("[ -f {$path} ]");
    }

    protected function symlinkExists(string $path): bool
    {
        return $this->test("[ -L {$path} ]");
    }

    protected function createDirectory(string $path): void
    {
        $this->run("mkdir -p {$path}");
    }

    protected function removeFile(string $path): void
    {
        $this->run("rm -f {$path}");
    }

    protected function removeDirectory(string $path): void
    {
        $this->run("rm -rf {$path}");
    }

    protected function createSymlink(string $target, string $link, bool $relative = true): void
    {
        $relativeFlag = $relative ? '--relative ' : '';
        $this->run("ln -nfs {$relativeFlag}{$target} {$link}");
    }
}
```

**Usage - Before**:

```php
$deployer->run("[ -d {$sharedPath} ] || mkdir -p {$sharedPath}");
$deployer->run("rm -rf {$releasePath}/storage");
$deployer->run("ln -nfs --relative {$sharedPath}/storage {$releasePath}/storage");
```

**Usage - After**:

```php
$this->createDirectory($sharedPath);
$this->removeDirectory("{$releasePath}/storage");
$this->createSymlink("{$sharedPath}/storage", "{$releasePath}/storage");
```

**Benefits**:
- ✅ More readable
- ✅ Reusable across all task classes
- ✅ Type-safe
- ✅ Easy to test

---

### Phase 3: Improve Error Handling

#### 3.1 Create Custom Exception Hierarchy

**New files**:

```
src/Exceptions/
├── DeploymentException.php         # Base exception
├── LockedException.php             # Deployment locked
├── ConfigurationException.php      # Invalid config
├── SSHConnectionException.php      # Connection failed
├── RsyncException.php              # Rsync failed
├── HealthCheckException.php        # Health check failed
└── TaskExecutionException.php      # Task execution failed
```

**Example - `DeploymentException.php`**:

```php
<?php

namespace Shaf\LaravelDeployer\Exceptions;

class DeploymentException extends \Exception
{
    public static function locked(string $lockFile): self
    {
        return new self("Deployment is locked. Lock file exists: {$lockFile}");
    }

    public static function taskFailed(string $taskName, string $reason): self
    {
        return new self("Task '{$taskName}' failed: {$reason}");
    }

    public static function releaseNotFound(string $release): self
    {
        return new self("Release '{$release}' does not exist");
    }
}
```

**Usage - Before**:

```php
throw new \RuntimeException("Deployment is locked");
```

**Usage - After**:

```php
throw DeploymentException::locked($lockFile);
```

**Benefits**:
- ✅ Named constructors for clarity
- ✅ Easy to catch specific errors
- ✅ Better error messages
- ✅ Type-safe

---

### Phase 4: Apply SOLID Principles

#### 4.1 Dependency Injection in Commands

**Before**:

```php
class DeployCommand extends Command
{
    public function handle(): int
    {
        $deployer = new Deployer($environment, $config);
        $deploymentTasks = new DeploymentTasks($deployer);
        $healthCheckTasks = new HealthCheckTasks($deployer);
        // ...
    }
}
```

**After**:

```php
class DeployCommand extends Command
{
    public function __construct(
        private ConfigurationService $configService,
        private CommandExecutorFactory $executorFactory,
        private OutputService $outputService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $config = $this->configService->load($environment);
        $executor = $this->executorFactory->create($config);

        $deploymentTasks = new DeploymentTasks($executor, $this->outputService, $config);
        // ...
    }
}
```

**Benefits**:
- ✅ Testable (can inject mocks)
- ✅ Follows Dependency Inversion Principle
- ✅ Laravel service container integration
- ✅ Easy to swap implementations

---

#### 4.2 Interface Segregation

**Create focused interfaces**:

```php
<?php

namespace Shaf\LaravelDeployer\Contracts;

interface ReleaseManager
{
    public function generateReleaseName(): string;
    public function getReleases(): array;
    public function getCurrentRelease(): ?string;
}

interface LockManager
{
    public function lock(): void;
    public function unlock(): void;
    public function isLocked(): bool;
}

interface HealthChecker
{
    public function checkResources(): void;
    public function checkEndpoints(array $endpoints): void;
}
```

**Benefits**:
- ✅ Small, focused interfaces
- ✅ Easy to implement
- ✅ Easy to mock in tests
- ✅ Follows ISP

---

### Phase 5: Configuration Management

#### 5.1 Extract Constants

**New files**:

```
src/Constants/
├── Paths.php                  # Deploy paths, directories
├── Commands.php               # Default commands
└── Timeouts.php               # Timeout values
```

**Example - `Paths.php`**:

```php
<?php

namespace Shaf\LaravelDeployer\Constants;

class Paths
{
    public const DEP_DIR = '.dep';
    public const RELEASES_DIR = 'releases';
    public const SHARED_DIR = 'shared';
    public const CURRENT_SYMLINK = 'current';
    public const RELEASE_SYMLINK = 'release';

    public const LOCK_FILE = self::DEP_DIR . '/deploy.lock';
    public const RELEASES_LOG = self::DEP_DIR . '/releases_log';
    public const LATEST_RELEASE = self::DEP_DIR . '/latest_release';
    public const COUNTER_DIR = self::DEP_DIR . '/release_counter';
}
```

**Example - `Commands.php`**:

```php
<?php

namespace Shaf\LaravelDeployer\Constants;

class Commands
{
    public const PHP_BINARY = '/usr/bin/php';
    public const COMPOSER_BINARY = 'composer';

    public const DEFAULT_COMPOSER_OPTIONS = '--verbose --prefer-dist --no-interaction --no-scripts --optimize-autoloader';
    public const PRODUCTION_COMPOSER_OPTIONS = '--no-dev --optimize-autoloader';
}
```

**Usage - Before**:

```php
$lockFile = $deployer->getDeployPath() . '/.dep/deploy.lock';
$phpPath = "/usr/bin/php";
```

**Usage - After**:

```php
use Shaf\LaravelDeployer\Constants\Paths;
use Shaf\LaravelDeployer\Constants\Commands;

$lockFile = $deployPath . '/' . Paths::LOCK_FILE;
$phpPath = Commands::PHP_BINARY;
```

**Benefits**:
- ✅ Single source of truth
- ✅ Easy to change
- ✅ IDE autocomplete
- ✅ No typos

---

### Phase 6: Improve Code Organization

#### 6.1 Split Large Classes

**Split `Deployer.php` (333 lines) into**:

```
src/Services/
├── DeploymentOrchestrator.php     # Main orchestration (~80 lines)
├── ReleaseManager.php             # Release name generation (~60 lines)
├── RsyncService.php               # Rsync operations (~80 lines)
└── EnvironmentLoader.php          # Environment loading (~60 lines)
```

**Benefits**:
- ✅ Each class has single responsibility
- ✅ Easier to understand
- ✅ Easier to test
- ✅ Easier to maintain

---

#### 6.2 Final Directory Structure

```
src/
├── Commands/              # Console commands
│   ├── DeployCommand.php
│   ├── RollbackCommand.php
│   ├── DatabaseBackupCommand.php
│   └── ...
├── Deployer/              # Task runners
│   ├── BaseTaskRunner.php
│   ├── DeploymentTasks.php
│   ├── ServiceTasks.php
│   ├── HealthCheckTasks.php
│   └── ...
├── Services/              # Business services
│   ├── ConfigurationService.php
│   ├── OutputService.php
│   ├── ArtisanTaskRunner.php
│   ├── ReleaseManager.php
│   ├── RsyncService.php
│   ├── CommandExecutorFactory.php
│   ├── RemoteCommandExecutor.php
│   ├── LocalCommandExecutor.php
│   └── ...
├── Data/                  # DTOs/Value Objects
│   ├── DeploymentConfig.php
│   ├── ReleaseInfo.php
│   ├── ServerConnection.php
│   └── TaskResult.php
├── Exceptions/            # Custom exceptions
│   ├── DeploymentException.php
│   ├── ConfigurationException.php
│   ├── SSHConnectionException.php
│   └── ...
├── Enums/                 # Enumerations
│   ├── Environment.php
│   ├── VerbosityLevel.php
│   └── TaskStatus.php
├── Constants/             # Constants
│   ├── Paths.php
│   ├── Commands.php
│   └── Timeouts.php
├── Contracts/             # Interfaces
│   ├── CommandExecutor.php
│   ├── ReleaseManager.php
│   ├── LockManager.php
│   └── ...
└── Concerns/              # Reusable traits
    ├── ExecutesCommands.php
    └── ManagesFiles.php
```

---

### Phase 7: Verbosity & Logging

#### 7.1 Implement Verbosity Levels

**Verbosity mapping**:

| Level | Flag | What's displayed |
|-------|------|------------------|
| Quiet | `--quiet` | Only errors and final result |
| Normal | (default) | Important steps (task names, success/failure) |
| Verbose | `-v` | Commands being executed |
| Very Verbose | `-vv` | Command output |
| Debug | `-vvv` | Everything (full debug info) |

**Example output**:

```bash
# Default (normal)
$ php artisan deploy staging
✓ Deployment started
✓ Health check passed
✓ Assets built
✓ Files synced
✓ Services restarted
✓ Deployment completed successfully!

# With -v
$ php artisan deploy staging -v
✓ Deployment started
task health:check-resources
task build:assets
run npm run build
task rsync
✓ Deployment completed successfully!

# With -vv
$ php artisan deploy staging -vv
✓ Deployment started
task health:check-resources
run df -h /var/www/app
Filesystem      Size  Used Avail Use% Mounted on
/dev/sda1       100G   45G   55G  45% /
task build:assets
run npm run build
> build
> vite build
✓ built in 3.2s
✓ Deployment completed successfully!

# With -vvv
$ php artisan deploy staging -vvv
[All output including debug info, full command outputs, etc.]
```

---

### Phase 8: Testing & Validation

#### 8.1 Add Input Validation

**Validate environments**:

```php
enum Environment: string
{
    case LOCAL = 'local';
    case STAGING = 'staging';
    case PRODUCTION = 'production';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new \InvalidArgumentException(
                "Invalid environment: {$value}. Valid: local, staging, production"
            );
    }
}
```

**Validate release names**:

```php
class ReleaseInfo
{
    private const PATTERN = '/^\d{6}\.\d+$/'; // 202501.1

    public function __construct(
        public readonly string $name,
        public readonly \DateTimeImmutable $createdAt,
        public readonly string $user,
        public readonly string $branch,
    ) {
        $this->validateName($name);
    }

    private function validateName(string $name): void
    {
        if (!preg_match(self::PATTERN, $name)) {
            throw new \InvalidArgumentException(
                "Invalid release name format: {$name}"
            );
        }
    }
}
```

---

#### 8.2 Add Type Hints Everywhere

**PHP 8.2+ features**:

```php
// Readonly properties
readonly class DeploymentConfig { /* ... */ }

// Constructor property promotion
public function __construct(
    private readonly CommandExecutor $executor,
    private readonly OutputService $output,
) {}

// Union types
public function getResult(): string|null { /* ... */ }

// Never return type
public function fail(): never {
    throw new DeploymentException('...');
}
```

---

## 📊 Expected Impact

### Code Metrics

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| Lines of code | ~2,800 | ~2,000 | -30% |
| Code duplication | ~400 lines | ~50 lines | -87% |
| Avg. class size | 180 lines | 80 lines | -55% |
| Cyclomatic complexity | High | Medium | -40% |
| Test coverage | 0% | 60%+ | +60% |

---

### Maintainability Improvements

✅ **Single Responsibility**: Each class has one clear purpose
✅ **DRY**: No code duplication
✅ **Type Safety**: Full type hints, DTOs, Enums
✅ **Error Handling**: Clear exception hierarchy
✅ **Testability**: Dependency injection, interfaces
✅ **Extensibility**: Easy to add new tasks/features
✅ **User Experience**: Proper verbosity levels

---

### Developer Experience

**Before**:
```php
// Hard to understand
$deployer->writeln("run cd {$deployPath} && (if [ -h release ]; then echo +precise; fi)");
$result = $deployer->run("cd {$deployPath} && (if [ -h release ]; then echo +precise; fi)");
if (!empty($result)) {
    $deployer->writeln($result);
}
```

**After**:
```php
// Clear and readable
if ($this->symlinkExists("{$deployPath}/release")) {
    $this->output->debug('Release symlink exists');
}
```

---

## 🚀 Implementation Order

### Priority 1: Foundation (Week 1)
- [ ] Phase 1.1: Create DTOs/Value Objects
- [ ] Phase 1.2: Create OutputService with verbosity
- [ ] Phase 1.3: Create ConfigurationService
- [ ] Phase 3: Create custom exceptions

### Priority 2: Core Refactoring (Week 2)
- [ ] Phase 1.4: Extract CommandExecutor
- [ ] Phase 2.1: Create ArtisanTaskRunner
- [ ] Phase 2.2: Create BaseTaskRunner
- [ ] Phase 2.3: Extract reusable helpers

### Priority 3: Cleanup (Week 3)
- [ ] Phase 5: Extract constants
- [ ] Phase 6: Split large classes
- [ ] Phase 4: Apply dependency injection
- [ ] Phase 8: Add validation

### Priority 4: Testing (Week 4)
- [ ] Write unit tests for services
- [ ] Write integration tests for tasks
- [ ] Add CI/CD pipeline
- [ ] Documentation updates

---

## 📚 References

### Spatie Standards
- [Spatie Guidelines](https://spatie.be/guidelines/laravel-php)
- [Package Development](https://spatie.be/docs/package-tools)
- Use of DTOs, Services, Actions pattern

### SOLID Principles
- **S**ingle Responsibility: One class, one reason to change
- **O**pen/Closed: Open for extension, closed for modification
- **L**iskov Substitution: Subtypes must be substitutable
- **I**nterface Segregation: Many small interfaces > one large
- **D**ependency Inversion: Depend on abstractions, not concretions

### Design Patterns Used
- **Strategy Pattern**: CommandExecutor (Local vs Remote)
- **Factory Pattern**: CommandExecutorFactory
- **Template Method**: BaseTaskRunner
- **Repository Pattern**: ConfigurationService
- **Value Object Pattern**: DTOs (DeploymentConfig, ReleaseInfo)

---

## ✅ Success Criteria

### Code Quality
- [ ] No code duplication (< 5% duplication)
- [ ] All classes < 150 lines
- [ ] All methods < 20 lines
- [ ] Cyclomatic complexity < 10
- [ ] Full type coverage (PHP 8.2+)

### Functionality
- [ ] All existing features work identically
- [ ] No breaking changes to public API
- [ ] Backward compatibility maintained
- [ ] All tests pass

### Documentation
- [ ] Updated README
- [ ] Inline documentation for public methods
- [ ] Migration guide for breaking changes (if any)
- [ ] Examples for common use cases

---

## 🔄 Next Steps

1. **Review this plan** with the team
2. **Prioritize phases** based on business needs
3. **Create feature branch** for refactoring
4. **Implement incrementally** (one phase at a time)
5. **Test thoroughly** after each phase
6. **Document changes** as you go

---

**Last Updated**: 2025-01-09
**Status**: Planning Phase
**Branch**: `claude/refactor-deployer-package-011CUwyZDtULafwBYD6mHj1v`
