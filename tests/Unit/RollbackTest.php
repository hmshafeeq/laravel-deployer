<?php

use Shaf\LaravelDeployer\Deployer\Deployer;
use Shaf\LaravelDeployer\Deployer\DeploymentTasks;

beforeEach(function () {
    // Set up test deployment configuration
    $this->deployPath = base_path('.deploy');
    $this->buildPath = $this->deployPath.'/builds';

    if (! is_dir($this->deployPath)) {
        mkdir($this->deployPath, 0755, true);
    }

    // Create test config
    $this->config = [
        'hostname' => 'localhost',
        'remote_user' => trim(shell_exec('whoami')),
        'deploy_path' => $this->buildPath,
        'repository' => base_path(),
        'branch' => 'main',
        'local' => true,
        'shared_dirs' => ['storage'],
        'shared_files' => ['.env'],
        'writable_dirs' => ['storage'],
        'keep_releases' => 3,
        'rsync' => ['src', 'tests'],
        'health_checks' => ['endpoints' => []],
    ];

    $this->deployer = new Deployer('test', $this->config);
    $this->tasks = new DeploymentTasks($this->deployer);
});

test('getReleases returns empty array when no releases exist', function () {
    $this->deployer->loadEnvironment();
    $releases = $this->tasks->getReleases();

    expect($releases)->toBeArray()->toBeEmpty();
});

test('getReleases returns list of releases', function () {
    $this->deployer->loadEnvironment();
    $this->tasks->setup();

    // Create fake releases
    $releasesPath = $this->buildPath.'/releases';
    mkdir($releasesPath.'/202501.1', 0755, true);
    sleep(1);
    mkdir($releasesPath.'/202501.2', 0755, true);
    sleep(1);
    mkdir($releasesPath.'/202501.3', 0755, true);

    $releases = $this->tasks->getReleases();

    expect($releases)
        ->toBeArray()
        ->toHaveCount(3)
        ->toContain('202501.1')
        ->toContain('202501.2')
        ->toContain('202501.3');

    // Should be sorted newest first
    expect($releases[0])->toBe('202501.3');
});

test('getCurrentRelease returns null when no current symlink exists', function () {
    $this->deployer->loadEnvironment();
    $current = $this->tasks->getCurrentRelease();

    expect($current)->toBeNull();
});

test('getCurrentRelease returns current release name', function () {
    $this->deployer->loadEnvironment();
    $this->tasks->setup();

    // Create a fake release and symlink
    $releasesPath = $this->buildPath.'/releases';
    $currentPath = $this->buildPath.'/current';
    mkdir($releasesPath.'/202501.1', 0755, true);
    symlink($releasesPath.'/202501.1', $currentPath);

    $current = $this->tasks->getCurrentRelease();

    expect($current)->toBe('202501.1');
});

test('getRollbackInfo indicates no rollback available with single release', function () {
    $this->deployer->loadEnvironment();
    $this->tasks->setup();

    // Create single release
    $releasesPath = $this->buildPath.'/releases';
    $currentPath = $this->buildPath.'/current';
    mkdir($releasesPath.'/202501.1', 0755, true);
    symlink($releasesPath.'/202501.1', $currentPath);

    $info = $this->tasks->getRollbackInfo();

    expect($info['can_rollback'])->toBeFalse();
    expect($info['previous'])->toBeNull();
});

test('getRollbackInfo indicates rollback available with multiple releases', function () {
    $this->deployer->loadEnvironment();
    $this->tasks->setup();

    // Create multiple releases
    $releasesPath = $this->buildPath.'/releases';
    $currentPath = $this->buildPath.'/current';
    mkdir($releasesPath.'/202501.1', 0755, true);
    sleep(1);
    mkdir($releasesPath.'/202501.2', 0755, true);

    // Current points to latest
    symlink($releasesPath.'/202501.2', $currentPath);

    $info = $this->tasks->getRollbackInfo();

    expect($info['can_rollback'])->toBeTrue();
    expect($info['previous'])->toBe('202501.1');
    expect($info['current'])->toBe('202501.2');
});

test('rollback changes current symlink to target release', function () {
    $this->deployer->loadEnvironment();
    $this->tasks->setup();

    // Create multiple releases
    $releasesPath = $this->buildPath.'/releases';
    $currentPath = $this->buildPath.'/current';
    mkdir($releasesPath.'/202501.1', 0755, true);
    mkdir($releasesPath.'/202501.2', 0755, true);
    symlink($releasesPath.'/202501.2', $currentPath);

    // Verify current is 202501.2
    expect($this->tasks->getCurrentRelease())->toBe('202501.2');

    // Rollback to 202501.1
    $this->tasks->rollback('202501.1');

    // Verify current is now 202501.1
    expect($this->tasks->getCurrentRelease())->toBe('202501.1');
});

test('rollback throws exception for non-existent release', function () {
    $this->deployer->loadEnvironment();
    $this->tasks->setup();

    expect(fn () => $this->tasks->rollback('nonexistent'))
        ->toThrow(\RuntimeException::class);
});
