<?php

use Shaf\LaravelDeployer\Constants\Paths;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\ReleaseInfo;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Exceptions\DeploymentException;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\DeploymentService;

beforeEach(function () {
    $this->config = new DeploymentConfig(
        environment: Environment::STAGING,
        hostname: 'staging.example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www/staging',
        branch: 'develop',
        composerOptions: '--no-dev',
        keepReleases: 3,
        isLocal: true // Local mode for testing
    );

    $this->basePath = sys_get_temp_dir().'/laravel-deployer-test-'.uniqid();
    mkdir($this->basePath, 0755, true);

    // Create a mock CommandService
    $this->mockCmd = Mockery::mock(CommandService::class);

    $this->service = new DeploymentService($this->config, $this->basePath);
    $this->service->setCommandService($this->mockCmd);
});

afterEach(function () {
    Mockery::close();

    if (is_dir($this->basePath)) {
        shell_exec("rm -rf {$this->basePath}");
    }
});

// =============================================================================
// Path Helper Tests
// =============================================================================

test('getDeployPath returns configured deploy path', function () {
    expect($this->service->getDeployPath())->toBe('/var/www/staging');
});

test('getReleasePath returns correct release directory', function () {
    $path = $this->service->getReleasePath('202501.1');

    expect($path)->toBe('/var/www/staging/'.Paths::RELEASES_DIR.'/202501.1');
});

test('getSharedPath returns shared directory', function () {
    $path = $this->service->getSharedPath();

    expect($path)->toBe('/var/www/staging/'.Paths::SHARED_DIR);
});

test('getCurrentPath returns current symlink path', function () {
    $path = $this->service->getCurrentPath();

    expect($path)->toBe('/var/www/staging/'.Paths::CURRENT_SYMLINK);
});

// =============================================================================
// Release Name Management Tests
// =============================================================================

test('setCurrentReleaseName and getCurrentReleaseName work together', function () {
    $this->service->setCurrentReleaseName('202501.5');

    expect($this->service->getCurrentReleaseName())->toBe('202501.5');
});

test('getCurrentReleaseName returns empty string initially', function () {
    $service = new DeploymentService($this->config, $this->basePath);

    expect($service->getCurrentReleaseName())->toBe('');
});

// =============================================================================
// generateReleaseName Tests
// =============================================================================

test('generateReleaseName creates YYYYMM.N format', function () {
    $counterDir = '/var/www/staging/'.Paths::COUNTER_DIR;
    $yearMonth = date('Ym');
    $counterFile = "{$counterDir}/{$yearMonth}.txt";

    // Mock the commands
    $this->mockCmd->shouldReceive('remote')
        ->with("mkdir -p {$counterDir}")
        ->once();

    $this->mockCmd->shouldReceive('remote')
        ->with("if [ -f {$counterFile} ]; then cat {$counterFile}; else echo 0; fi")
        ->once()
        ->andReturn('0');

    $this->mockCmd->shouldReceive('remote')
        ->with("echo 1 > {$counterFile}")
        ->once();

    $this->mockCmd->shouldReceive('debug')
        ->once();

    $releaseName = $this->service->generateReleaseName();

    expect($releaseName)->toBe("{$yearMonth}.1");
    expect($this->service->getCurrentReleaseName())->toBe("{$yearMonth}.1");
});

test('generateReleaseName increments counter', function () {
    $counterDir = '/var/www/staging/'.Paths::COUNTER_DIR;
    $yearMonth = date('Ym');
    $counterFile = "{$counterDir}/{$yearMonth}.txt";

    $this->mockCmd->shouldReceive('remote')
        ->with("mkdir -p {$counterDir}")
        ->once();

    $this->mockCmd->shouldReceive('remote')
        ->with("if [ -f {$counterFile} ]; then cat {$counterFile}; else echo 0; fi")
        ->once()
        ->andReturn('5'); // Already at 5

    $this->mockCmd->shouldReceive('remote')
        ->with("echo 6 > {$counterFile}")
        ->once();

    $this->mockCmd->shouldReceive('debug')
        ->once();

    $releaseName = $this->service->generateReleaseName();

    expect($releaseName)->toBe("{$yearMonth}.6");
});

// =============================================================================
// Lock Management Tests
// =============================================================================

test('check passes when not locked', function () {
    $this->mockCmd->shouldReceive('debug')->twice();
    $this->mockCmd->shouldReceive('fileExists')
        ->with('/var/www/staging/'.Paths::LOCK_FILE)
        ->once()
        ->andReturn(false);

    // Should not throw
    $this->service->check();
    expect(true)->toBeTrue();
});

test('check throws when locked', function () {
    $this->mockCmd->shouldReceive('debug')->once();
    $this->mockCmd->shouldReceive('fileExists')
        ->with('/var/www/staging/'.Paths::LOCK_FILE)
        ->once()
        ->andReturn(true);

    expect(fn () => $this->service->check())
        ->toThrow(DeploymentException::class);
});

test('lock creates lock file', function () {
    $lockFile = '/var/www/staging/'.Paths::LOCK_FILE;

    $this->mockCmd->shouldReceive('debug')->twice();
    $this->mockCmd->shouldReceive('remote')
        ->with(Mockery::on(function ($arg) use ($lockFile) {
            return str_starts_with($arg, "echo '") && str_ends_with($arg, "' > {$lockFile}");
        }))
        ->once();

    $this->service->lock();

    // Just verify no exception thrown
    expect(true)->toBeTrue();
});

test('unlock removes lock file', function () {
    $lockFile = '/var/www/staging/'.Paths::LOCK_FILE;

    $this->mockCmd->shouldReceive('debug')->twice();
    $this->mockCmd->shouldReceive('remote')
        ->with("rm -f {$lockFile}")
        ->once();

    $this->service->unlock();

    expect(true)->toBeTrue();
});

test('isLocked returns true when lock file exists', function () {
    $this->mockCmd->shouldReceive('fileExists')
        ->with('/var/www/staging/'.Paths::LOCK_FILE)
        ->once()
        ->andReturn(true);

    expect($this->service->isLocked())->toBeTrue();
});

test('isLocked returns false when no lock file', function () {
    $this->mockCmd->shouldReceive('fileExists')
        ->with('/var/www/staging/'.Paths::LOCK_FILE)
        ->once()
        ->andReturn(false);

    expect($this->service->isLocked())->toBeFalse();
});

test('getLockedBy returns null when not locked', function () {
    $this->mockCmd->shouldReceive('fileExists')
        ->once()
        ->andReturn(false);

    expect($this->service->getLockedBy())->toBeNull();
});

test('getLockedBy returns username when locked', function () {
    $lockFile = '/var/www/staging/'.Paths::LOCK_FILE;

    $this->mockCmd->shouldReceive('fileExists')
        ->once()
        ->andReturn(true);

    $this->mockCmd->shouldReceive('remote')
        ->with("cat {$lockFile} 2>/dev/null || echo ''")
        ->once()
        ->andReturn('john');

    expect($this->service->getLockedBy())->toBe('john');
});

// =============================================================================
// Release Listing Tests
// =============================================================================

test('getReleases returns empty array when directory does not exist', function () {
    $releasesPath = '/var/www/staging/'.Paths::RELEASES_DIR;

    $this->mockCmd->shouldReceive('directoryExists')
        ->with($releasesPath)
        ->once()
        ->andReturn(false);

    expect($this->service->getReleases())->toBe([]);
});

test('getReleases returns sorted releases', function () {
    $releasesPath = '/var/www/staging/'.Paths::RELEASES_DIR;

    $this->mockCmd->shouldReceive('directoryExists')
        ->with($releasesPath)
        ->once()
        ->andReturn(true);

    $this->mockCmd->shouldReceive('remote')
        ->with("cd {$releasesPath} && ls -t -1 2>/dev/null || echo ''")
        ->once()
        ->andReturn("202501.3\n202501.2\n202501.1");

    $releases = $this->service->getReleases();

    expect($releases)->toBe(['202501.3', '202501.2', '202501.1']);
});

test('getReleases returns empty array for empty output', function () {
    $releasesPath = '/var/www/staging/'.Paths::RELEASES_DIR;

    $this->mockCmd->shouldReceive('directoryExists')
        ->with($releasesPath)
        ->once()
        ->andReturn(true);

    $this->mockCmd->shouldReceive('remote')
        ->once()
        ->andReturn('');

    expect($this->service->getReleases())->toBe([]);
});

// =============================================================================
// Current Release Tests
// =============================================================================

test('getCurrentRelease returns null when symlink does not exist', function () {
    $currentPath = '/var/www/staging/'.Paths::CURRENT_SYMLINK;

    $this->mockCmd->shouldReceive('symlinkExists')
        ->with($currentPath)
        ->once()
        ->andReturn(false);

    expect($this->service->getCurrentRelease())->toBeNull();
});

test('getCurrentRelease returns release name from symlink', function () {
    $currentPath = '/var/www/staging/'.Paths::CURRENT_SYMLINK;

    $this->mockCmd->shouldReceive('symlinkExists')
        ->with($currentPath)
        ->once()
        ->andReturn(true);

    $this->mockCmd->shouldReceive('remote')
        ->with("basename \$(readlink -f {$currentPath}) 2>/dev/null || echo ''")
        ->once()
        ->andReturn('202501.3');

    expect($this->service->getCurrentRelease())->toBe('202501.3');
});

// =============================================================================
// Previous Release Tests
// =============================================================================

test('getPreviousRelease returns null when less than 2 releases', function () {
    $releasesPath = '/var/www/staging/'.Paths::RELEASES_DIR;
    $currentPath = '/var/www/staging/'.Paths::CURRENT_SYMLINK;

    $this->mockCmd->shouldReceive('symlinkExists')
        ->with($currentPath)
        ->once()
        ->andReturn(true);

    $this->mockCmd->shouldReceive('remote')
        ->with("basename \$(readlink -f {$currentPath}) 2>/dev/null || echo ''")
        ->once()
        ->andReturn('202501.1');

    $this->mockCmd->shouldReceive('directoryExists')
        ->with($releasesPath)
        ->once()
        ->andReturn(true);

    $this->mockCmd->shouldReceive('remote')
        ->with("cd {$releasesPath} && ls -t -1 2>/dev/null || echo ''")
        ->once()
        ->andReturn('202501.1'); // Only one release

    expect($this->service->getPreviousRelease())->toBeNull();
});

test('getPreviousRelease returns previous release', function () {
    $releasesPath = '/var/www/staging/'.Paths::RELEASES_DIR;
    $currentPath = '/var/www/staging/'.Paths::CURRENT_SYMLINK;

    $this->mockCmd->shouldReceive('symlinkExists')
        ->with($currentPath)
        ->once()
        ->andReturn(true);

    $this->mockCmd->shouldReceive('remote')
        ->with("basename \$(readlink -f {$currentPath}) 2>/dev/null || echo ''")
        ->once()
        ->andReturn('202501.3');

    $this->mockCmd->shouldReceive('directoryExists')
        ->with($releasesPath)
        ->once()
        ->andReturn(true);

    $this->mockCmd->shouldReceive('remote')
        ->with("cd {$releasesPath} && ls -t -1 2>/dev/null || echo ''")
        ->once()
        ->andReturn("202501.3\n202501.2\n202501.1");

    expect($this->service->getPreviousRelease())->toBe('202501.2');
});

// =============================================================================
// Release Logging Tests
// =============================================================================

test('writeLatestRelease writes release name to file', function () {
    $latestFile = '/var/www/staging/'.Paths::LATEST_RELEASE;

    $this->mockCmd->shouldReceive('remote')
        ->with("echo 202501.5 > {$latestFile}")
        ->once();

    $this->service->writeLatestRelease('202501.5');

    expect(true)->toBeTrue();
});

test('logRelease writes JSON to releases log', function () {
    $logFile = '/var/www/staging/'.Paths::RELEASES_LOG;

    $release = new ReleaseInfo(
        name: '202501.5',
        createdAt: new DateTimeImmutable('2025-01-15T10:30:00+00:00'),
        user: 'deploy',
        branch: 'main'
    );

    $this->mockCmd->shouldReceive('remote')
        ->with(Mockery::on(function ($arg) use ($logFile) {
            return str_starts_with($arg, "echo '") && str_contains($arg, '202501.5') && str_ends_with($arg, "' >> {$logFile}");
        }))
        ->once();

    $this->service->logRelease($release);

    expect(true)->toBeTrue();
});

// =============================================================================
// User Detection Tests
// =============================================================================

test('getUser returns deployer for non-local config', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www/app',
        branch: 'main',
        composerOptions: '--no-dev',
        isLocal: false
    );

    $service = new DeploymentService($config, $this->basePath);

    expect($service->getUser())->toBe('deployer');
});

test('getUser returns git user for local config', function () {
    // This test depends on git being configured
    $user = $this->service->getUser();

    // Should return something (git user or 'unknown')
    expect($user)->not->toBeEmpty();
});
