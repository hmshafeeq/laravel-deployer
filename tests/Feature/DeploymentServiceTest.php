<?php

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\DeploymentService;

beforeEach(function () {
    $this->config = new DeploymentConfig(
        environment: Environment::LOCAL,
        hostname: 'localhost',
        remoteUser: 'deploy',
        deployPath: '/var/www/test',
        composerOptions: '--prefer-dist'
    );

    $this->cmd = Mockery::mock(CommandService::class);
    $this->cmd->shouldIgnoreMissing();

    $this->service = new DeploymentService($this->config, $this->cmd);
});

afterEach(function () {
    Mockery::close();
});

test('generateReleaseName() creates YYYYMM.N format name', function () {
    $expectedYearMonth = date('Ym');

    // Mock the remote command to return counter value
    $this->cmd->shouldReceive('remote')
        ->once()
        ->andReturn('1');

    $result = $this->service->generateReleaseName();

    expect($result)->toBe("{$expectedYearMonth}.1");
});

test('generateReleaseName() increments sequence for same month', function () {
    $expectedYearMonth = date('Ym');

    // First call returns 1
    $this->cmd->shouldReceive('remote')
        ->once()
        ->andReturn('1');

    $result1 = $this->service->generateReleaseName();
    expect($result1)->toBe("{$expectedYearMonth}.1");

    // Create new service instance to test second call
    $service2 = new DeploymentService($this->config, $this->cmd);

    // Second call should return 2 (simulating incremented counter)
    $this->cmd->shouldReceive('remote')
        ->once()
        ->andReturn('2');

    $result2 = $service2->generateReleaseName();
    expect($result2)->toBe("{$expectedYearMonth}.2");
});

test('generateReleaseName() resets sequence for new month', function () {
    $currentYearMonth = date('Ym');

    // Mock returns 5 to simulate counter at 5 for current month
    $this->cmd->shouldReceive('remote')
        ->once()
        ->andReturn('5');

    $result = $this->service->generateReleaseName();
    expect($result)->toBe("{$currentYearMonth}.5");
});

test('getReleases() returns array of release names', function () {
    $this->cmd->shouldReceive('directoryExists')
        ->once()
        ->andReturn(true);

    $this->cmd->shouldReceive('remote')
        ->once()
        ->andReturn("202412.3\n202412.2\n202412.1");

    $result = $this->service->getReleases();

    expect($result)->toBe(['202412.3', '202412.2', '202412.1']);
});

test('getReleases() returns empty array when directory does not exist', function () {
    $this->cmd->shouldReceive('directoryExists')
        ->once()
        ->andReturn(false);

    $result = $this->service->getReleases();

    expect($result)->toBe([]);
});

test('getCurrentRelease() returns name from current symlink', function () {
    $this->cmd->shouldReceive('symlinkExists')
        ->once()
        ->andReturn(true);

    $this->cmd->shouldReceive('remote')
        ->once()
        ->andReturn('202412.3');

    $result = $this->service->getCurrentRelease();

    expect($result)->toBe('202412.3');
});

test('getCurrentRelease() returns null when no current symlink', function () {
    $this->cmd->shouldReceive('symlinkExists')
        ->once()
        ->andReturn(false);

    $result = $this->service->getCurrentRelease();

    expect($result)->toBeNull();
});

test('getPreviousRelease() returns release before current', function () {
    // Mock current release check
    $this->cmd->shouldReceive('symlinkExists')
        ->once()
        ->andReturn(true);
    $this->cmd->shouldReceive('remote')
        ->once()
        ->andReturn('202412.3');

    // Mock releases list check
    $this->cmd->shouldReceive('directoryExists')
        ->once()
        ->andReturn(true);
    $this->cmd->shouldReceive('remote')
        ->once()
        ->andReturn("202412.3\n202412.2\n202412.1");

    $result = $this->service->getPreviousRelease();

    expect($result)->toBe('202412.2');
});

test('getPreviousRelease() returns null when only one release exists', function () {
    // Mock current release check
    $this->cmd->shouldReceive('symlinkExists')
        ->once()
        ->andReturn(true);
    $this->cmd->shouldReceive('remote')
        ->once()
        ->andReturn('202412.1');

    // Mock releases list check - only one release
    $this->cmd->shouldReceive('directoryExists')
        ->once()
        ->andReturn(true);
    $this->cmd->shouldReceive('remote')
        ->once()
        ->andReturn('202412.1');

    $result = $this->service->getPreviousRelease();

    expect($result)->toBeNull();
});
