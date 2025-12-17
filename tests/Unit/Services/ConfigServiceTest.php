<?php

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Exceptions\ConfigurationException;
use Shaf\LaravelDeployer\Services\ConfigService;

beforeEach(function () {
    $this->fixturesPath = __DIR__.'/../../Fixtures';
    $this->tempPath = sys_get_temp_dir().'/laravel-deployer-test-'.uniqid();

    // Create temp directory
    mkdir($this->tempPath, 0755, true);
    mkdir($this->tempPath.'/.deploy', 0755, true);
});

afterEach(function () {
    // Clear any DEPLOY_* environment variables
    foreach (['DEPLOY_HOST', 'DEPLOY_USER', 'DEPLOY_PATH', 'DEPLOY_BRANCH'] as $key) {
        unset($_ENV[$key]);
        putenv($key);
    }

    // Cleanup temp directory
    if (is_dir($this->tempPath)) {
        shell_exec("rm -rf {$this->tempPath}");
    }
});

// =============================================================================
// Configuration Loading Tests
// =============================================================================

test('load returns DeploymentConfig for valid environment', function () {
    // Copy fixture to temp path
    copy($this->fixturesPath.'/deploy.yaml', $this->tempPath.'/.deploy/deploy.yaml');

    $config = ConfigService::load('staging', $this->tempPath);

    expect($config)->toBeInstanceOf(DeploymentConfig::class);
    expect($config->environment)->toBe(Environment::STAGING);
    expect($config->hostname)->toBe('staging.example.com');
    expect($config->remoteUser)->toBe('deploy');
    expect($config->deployPath)->toBe('/var/www/staging');
    expect($config->branch)->toBe('develop');
});

test('loadConfig loads production configuration', function () {
    copy($this->fixturesPath.'/deploy.yaml', $this->tempPath.'/.deploy/deploy.yaml');

    $service = new ConfigService($this->tempPath);
    $config = $service->loadConfig('production');

    expect($config->environment)->toBe(Environment::PRODUCTION);
    expect($config->hostname)->toBe('example.com');
    expect($config->deployPath)->toBe('/var/www/production');
    expect($config->port)->toBe(22);
});

test('loadConfig applies global config', function () {
    copy($this->fixturesPath.'/deploy.yaml', $this->tempPath.'/.deploy/deploy.yaml');

    $service = new ConfigService($this->tempPath);
    $config = $service->loadConfig('staging');

    // Global config values
    expect($config->keepReleases)->toBe(3);
    expect($config->application)->toBe('TestApp');
    expect($config->showDiff)->toBeTrue();
    expect($config->confirmChanges)->toBeFalse();
    expect($config->diffDisplayLimit)->toBe(25);
    expect($config->rsyncExcludes)->toBe(['.git', 'node_modules', '.env', 'tests']);
    expect($config->rsyncIncludes)->toBe(['app/', 'config/']);
});

test('loadConfig handles local environment', function () {
    copy($this->fixturesPath.'/deploy.yaml', $this->tempPath.'/.deploy/deploy.yaml');

    $service = new ConfigService($this->tempPath);
    $config = $service->loadConfig('local');

    expect($config->environment)->toBe(Environment::LOCAL);
    expect($config->isLocal)->toBeTrue();
});

// =============================================================================
// Configuration File Location Tests
// =============================================================================

test('loadConfig finds config in .deploy directory', function () {
    copy($this->fixturesPath.'/deploy.yaml', $this->tempPath.'/.deploy/deploy.yaml');

    $service = new ConfigService($this->tempPath);
    $config = $service->loadConfig('staging');

    expect($config)->toBeInstanceOf(DeploymentConfig::class);
});

test('loadConfig finds config in root directory', function () {
    copy($this->fixturesPath.'/deploy.yaml', $this->tempPath.'/deploy.yaml');

    $service = new ConfigService($this->tempPath);
    $config = $service->loadConfig('staging');

    expect($config)->toBeInstanceOf(DeploymentConfig::class);
});

test('loadConfig throws when no config file found', function () {
    $service = new ConfigService($this->tempPath);

    expect(fn () => $service->loadConfig('staging'))
        ->toThrow(ConfigurationException::class);
});

// =============================================================================
// Environment Validation Tests
// =============================================================================

test('loadConfig throws for non-existent environment', function () {
    copy($this->fixturesPath.'/deploy.yaml', $this->tempPath.'/.deploy/deploy.yaml');

    $service = new ConfigService($this->tempPath);

    expect(fn () => $service->loadConfig('nonexistent'))
        ->toThrow(ConfigurationException::class);
});

// =============================================================================
// getAvailableEnvironments Tests
// =============================================================================

test('getAvailableEnvironments returns all defined environments', function () {
    copy($this->fixturesPath.'/deploy.yaml', $this->tempPath.'/.deploy/deploy.yaml');

    $service = new ConfigService($this->tempPath);
    $environments = $service->getAvailableEnvironments();

    expect($environments)->toContain('test');
    expect($environments)->toContain('local');
    expect($environments)->toContain('staging');
    expect($environments)->toContain('production');
    expect($environments)->toHaveCount(4);
});

test('getAvailableEnvironments returns empty array when no config', function () {
    $service = new ConfigService($this->tempPath);
    $environments = $service->getAvailableEnvironments();

    expect($environments)->toBe([]);
});

// =============================================================================
// Invalid YAML Tests
// =============================================================================

test('loadConfig throws for invalid YAML', function () {
    file_put_contents($this->tempPath.'/.deploy/deploy.yaml', "invalid: yaml: content:\n  - broken");

    $service = new ConfigService($this->tempPath);

    expect(fn () => $service->loadConfig('staging'))
        ->toThrow(ConfigurationException::class);
});

// =============================================================================
// Environment Variable Override Tests
// =============================================================================

test('loadConfig merges environment variables', function () {
    copy($this->fixturesPath.'/deploy.yaml', $this->tempPath.'/.deploy/deploy.yaml');

    // Create .env.staging file
    file_put_contents($this->tempPath.'/.deploy/.env.staging', <<<'ENV'
DEPLOY_HOST=override.example.com
DEPLOY_USER=override-user
DEPLOY_PATH=/var/www/override
DEPLOY_BRANCH=feature/test
ENV
    );

    $service = new ConfigService($this->tempPath);
    $config = $service->loadConfig('staging');

    expect($config->hostname)->toBe('override.example.com');
    expect($config->remoteUser)->toBe('override-user');
    expect($config->deployPath)->toBe('/var/www/override');
    expect($config->branch)->toBe('feature/test');
});

test('loadConfig works without environment file', function () {
    copy($this->fixturesPath.'/deploy.yaml', $this->tempPath.'/.deploy/deploy.yaml');

    // No .env.staging file

    $service = new ConfigService($this->tempPath);
    $config = $service->loadConfig('staging');

    // Should use YAML values
    expect($config->hostname)->toBe('staging.example.com');
    expect($config->remoteUser)->toBe('deploy');
});
