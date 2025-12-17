<?php

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Enums\Environment;

// =============================================================================
// Constructor Tests
// =============================================================================

test('DeploymentConfig can be created with all parameters', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www/app',
        branch: 'main',
        composerOptions: '--no-dev',
        keepReleases: 5,
        isLocal: false,
        application: 'MyApp',
        rsyncExcludes: ['.git'],
        rsyncIncludes: ['app/'],
        port: 22,
        showDiff: true,
        confirmChanges: true,
        showUploadProgress: true,
        diffDisplayLimit: 30,
        phpBinary: '/usr/bin/php'
    );

    expect($config->environment)->toBe(Environment::PRODUCTION);
    expect($config->hostname)->toBe('example.com');
    expect($config->remoteUser)->toBe('deploy');
    expect($config->deployPath)->toBe('/var/www/app');
    expect($config->branch)->toBe('main');
    expect($config->composerOptions)->toBe('--no-dev');
    expect($config->keepReleases)->toBe(5);
    expect($config->isLocal)->toBeFalse();
    expect($config->application)->toBe('MyApp');
    expect($config->rsyncExcludes)->toBe(['.git']);
    expect($config->rsyncIncludes)->toBe(['app/']);
    expect($config->port)->toBe(22);
    expect($config->showDiff)->toBeTrue();
    expect($config->confirmChanges)->toBeTrue();
    expect($config->showUploadProgress)->toBeTrue();
    expect($config->diffDisplayLimit)->toBe(30);
    expect($config->phpBinary)->toBe('/usr/bin/php');
});

test('DeploymentConfig uses default values', function () {
    $config = new DeploymentConfig(
        environment: Environment::STAGING,
        hostname: 'staging.example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www/staging',
        branch: 'develop',
        composerOptions: '--no-dev'
    );

    expect($config->keepReleases)->toBe(3);
    expect($config->isLocal)->toBeFalse();
    expect($config->application)->toBe('Application');
    expect($config->rsyncExcludes)->toBe([]);
    expect($config->rsyncIncludes)->toBe([]);
    expect($config->port)->toBeNull();
    expect($config->showDiff)->toBeTrue();
    expect($config->confirmChanges)->toBeTrue();
    expect($config->showUploadProgress)->toBeTrue();
    expect($config->diffDisplayLimit)->toBe(20);
    expect($config->phpBinary)->toBe('php');
});

// =============================================================================
// fromArray() Factory Tests
// =============================================================================

test('fromArray creates config from environment and host config', function () {
    $config = DeploymentConfig::fromArray('production', [
        'hostname' => 'prod.example.com',
        'remote_user' => 'deployer',
        'deploy_path' => '/var/www/production',
        'branch' => 'main',
    ]);

    expect($config->environment)->toBe(Environment::PRODUCTION);
    expect($config->hostname)->toBe('prod.example.com');
    expect($config->remoteUser)->toBe('deployer');
    expect($config->deployPath)->toBe('/var/www/production');
    expect($config->branch)->toBe('main');
});

test('fromArray applies global config overrides', function () {
    $config = DeploymentConfig::fromArray('staging', [
        'hostname' => 'staging.example.com',
        'remote_user' => 'deploy',
        'deploy_path' => '/var/www/staging',
        'branch' => 'develop',
    ], [
        'keep_releases' => 5,
        'application' => 'TestApp',
        'show_diff' => false,
        'confirm_changes' => false,
        'diff_display_limit' => 50,
        'rsync' => [
            'exclude' => ['.git', 'node_modules'],
            'include' => ['app/', 'config/'],
        ],
    ]);

    expect($config->keepReleases)->toBe(5);
    expect($config->application)->toBe('TestApp');
    expect($config->showDiff)->toBeFalse();
    expect($config->confirmChanges)->toBeFalse();
    expect($config->diffDisplayLimit)->toBe(50);
    expect($config->rsyncExcludes)->toBe(['.git', 'node_modules']);
    expect($config->rsyncIncludes)->toBe(['app/', 'config/']);
});

test('fromArray uses defaults for missing values', function () {
    $config = DeploymentConfig::fromArray('local', []);

    expect($config->hostname)->toBe('localhost');
    expect($config->remoteUser)->toBe('deploy');
    expect($config->deployPath)->toBe('/var/www/app');
    expect($config->branch)->toBe('main');
});

test('fromArray handles environment aliases', function () {
    $prod = DeploymentConfig::fromArray('prod', ['hostname' => 'prod.example.com']);
    $stage = DeploymentConfig::fromArray('stg', ['hostname' => 'stg.example.com']);
    $dev = DeploymentConfig::fromArray('dev', ['hostname' => 'localhost']);

    expect($prod->environment)->toBe(Environment::PRODUCTION);
    expect($stage->environment)->toBe(Environment::STAGING);
    expect($dev->environment)->toBe(Environment::LOCAL);
});

test('fromArray sets isLocal from config', function () {
    $local = DeploymentConfig::fromArray('local', [
        'local' => true,
    ]);

    expect($local->isLocal)->toBeTrue();
});

test('fromArray handles port configuration', function () {
    $config = DeploymentConfig::fromArray('production', [
        'hostname' => 'example.com',
        'port' => 2222,
    ]);

    expect($config->port)->toBe(2222);
});

test('fromArray handles php binary configuration', function () {
    $config1 = DeploymentConfig::fromArray('production', [
        'bin/php' => '/usr/local/bin/php',
    ]);

    $config2 = DeploymentConfig::fromArray('production', [
        'php_binary' => '/opt/php/bin/php',
    ]);

    expect($config1->phpBinary)->toBe('/usr/local/bin/php');
    expect($config2->phpBinary)->toBe('/opt/php/bin/php');
});

// =============================================================================
// toArray() Tests
// =============================================================================

test('toArray returns array representation', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www/app',
        branch: 'main',
        composerOptions: '--no-dev',
        keepReleases: 3,
        isLocal: false,
        application: 'MyApp',
        port: 22
    );

    $array = $config->toArray();

    expect($array)->toBeArray();
    expect($array['environment'])->toBe('production');
    expect($array['hostname'])->toBe('example.com');
    expect($array['remote_user'])->toBe('deploy');
    expect($array['deploy_path'])->toBe('/var/www/app');
    expect($array['branch'])->toBe('main');
    expect($array['composer_options'])->toBe('--no-dev');
    expect($array['keep_releases'])->toBe(3);
    expect($array['local'])->toBeFalse();
    expect($array['application'])->toBe('MyApp');
    expect($array['port'])->toBe(22);
});

test('toArray includes null port when not set', function () {
    $config = DeploymentConfig::fromArray('production', [
        'hostname' => 'example.com',
    ]);

    $array = $config->toArray();

    expect($array['port'])->toBeNull();
});

// =============================================================================
// Readonly Property Tests
// =============================================================================

test('DeploymentConfig is readonly', function () {
    $config = DeploymentConfig::fromArray('production', [
        'hostname' => 'example.com',
    ]);

    $reflection = new ReflectionClass($config);
    expect($reflection->isReadOnly())->toBeTrue();
});
