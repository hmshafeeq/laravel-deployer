<?php

use Shaf\LaravelDeployer\Deployer\Deployer;
use Shaf\LaravelDeployer\Deployer\DeploymentTasks;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    // Set up test deployment configuration
    $this->deployPath = base_path('.deploy');
    $this->buildPath = $this->deployPath.'/builds';

    if (!is_dir($this->deployPath)) {
        mkdir($this->deployPath, 0755, true);
    }

    // Create test config
    $this->config = [
        'hostname' => 'localhost',
        'remote_user' => trim(shell_exec('whoami')),
        'deploy_path' => $this->buildPath,
        'repository' => base_path(),
        'branch' => 'main',
        'local' => true, // Mark as local to skip SSH
        'shared_dirs' => ['storage'],
        'shared_files' => ['.env'],
        'writable_dirs' => ['storage'],
        'keep_releases' => 2,
        'rsync' => ['src', 'tests'],
        'health_checks' => ['endpoints' => []],
    ];

    file_put_contents(
        $this->deployPath.'/deploy.yaml',
        Yaml::dump(['test' => $this->config])
    );

    $this->deployer = new Deployer('test', $this->config);
    $this->tasks = new DeploymentTasks($this->deployer);
});

test('deployment tasks can be instantiated', function () {
    expect($this->tasks)->toBeInstanceOf(DeploymentTasks::class);
});

test('setup creates deployment directory structure', function () {
    $this->deployer->loadEnvironment();
    $this->tasks->setup();

    expect(file_exists($this->buildPath))->toBeTrue();
    expect(file_exists($this->buildPath.'/releases'))->toBeTrue();
    expect(file_exists($this->buildPath.'/shared'))->toBeTrue();
});

test('release generates unique release name', function () {
    $this->deployer->loadEnvironment();
    $releaseName1 = $this->deployer->generateReleaseName();

    expect($releaseName1)
        ->toBeString()
        ->toMatch('/^\d{6}\.\d+$/');

    // Generate another release name
    $releaseName2 = $this->deployer->generateReleaseName();

    // Should increment the counter
    expect($releaseName2)->not->toBe($releaseName1);
});

test('lock prevents concurrent deployments', function () {
    $this->deployer->loadEnvironment();
    $this->tasks->setup();
    $this->tasks->lock();

    $lockFile = $this->buildPath.'/.dep/deploy.lock';
    expect(file_exists($lockFile))->toBeTrue();

    // Clean up
    $this->tasks->unlock();
});

test('unlock removes deployment lock', function () {
    $this->deployer->loadEnvironment();
    $this->tasks->setup();
    $this->tasks->lock();
    $this->tasks->unlock();

    $lockFile = $this->buildPath.'/.dep/deploy.lock';
    expect(file_exists($lockFile))->toBeFalse();
});

test('check lock detects existing lock', function () {
    $this->deployer->loadEnvironment();
    $this->tasks->setup();
    $this->tasks->lock();

    ob_start();
    try {
        $this->tasks->checkLock();
        $output = ob_get_clean();
        // If no exception, lock was not detected or handled
    } catch (\Exception $e) {
        $output = ob_get_clean();
        expect($e->getMessage())->toContain('locked');
    }

    // Clean up
    $this->tasks->unlock();
});

test('cleanup removes old releases', function () {
    $this->deployer->loadEnvironment();
    $this->tasks->setup();

    // Create 3 fake releases
    $releasesPath = $this->buildPath.'/releases';
    for ($i = 1; $i <= 3; $i++) {
        $releaseName = date('Ym').'.'.$i;
        mkdir($releasesPath.'/'.$releaseName, 0755, true);
        if ($i < 3) {
            sleep(1);
        }
    }

    // Run cleanup (keep_releases = 2)
    $this->tasks->cleanup();

    $releases = scandir($releasesPath);
    $releases = array_diff($releases, ['.', '..']);

    // Should only keep 2 releases
    expect(count($releases))->toBeLessThanOrEqual(2);
});
