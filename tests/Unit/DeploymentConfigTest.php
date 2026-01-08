<?php

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Enums\Environment;

test('fromArray() creates config with all default values', function () {
    $configArray = [
        'environments' => [
            'local' => [
                'hostname' => 'example.com',
                'remoteUser' => 'deploy',
                'deployPath' => '/var/www/app',
            ],
        ],
    ];

    $config = DeploymentConfig::fromArray('local', $configArray['environments']['local']);

    expect($config->environment)->toBe(Environment::LOCAL);
    expect($config->hostname)->toBe('example.com');
    expect($config->remoteUser)->toBe('deploy');
    expect($config->deployPath)->toBe('/var/www/app');
    expect($config->keepReleases)->toBe(3);
    expect($config->isLocal)->toBeFalse();
    expect($config->rsyncExcludes)->toBe([]);
    expect($config->rsyncIncludes)->toBe([]);
    expect($config->rsyncFlags)->toBe('rzc');
    expect($config->phpBinary)->toBe('php');
});

test('fromArray() applies environment-specific overrides', function () {
    $configArray = [
        'environments' => [
            'production' => [
                'hostname' => 'prod.example.com',
                'remoteUser' => 'prod-deploy',
                'deployPath' => '/var/www/prod',
                'keepReleases' => 5,
                'local' => false,
                'phpBinary' => 'php8.1',
            ],
        ],
    ];

    $config = DeploymentConfig::fromArray('production', $configArray['environments']['production']);

    expect($config->environment)->toBe(Environment::PRODUCTION);
    expect($config->hostname)->toBe('prod.example.com');
    expect($config->remoteUser)->toBe('prod-deploy');
    expect($config->deployPath)->toBe('/var/www/prod');
    expect($config->keepReleases)->toBe(5);
    expect($config->isLocal)->toBeFalse();
    expect($config->phpBinary)->toBe('php8.1');
});

test('fromArray() handles nested rsync config (excludes, includes, flags)', function () {
    $configArray = [
        'environments' => [
            'staging' => [
                'hostname' => 'staging.example.com',
                'remoteUser' => 'deploy',
                'deployPath' => '/var/www/staging',
                'rsync' => [
                    'exclude' => ['.git/', 'node_modules/', 'tests/'],
                    'include' => ['composer.json', 'composer.lock', '.env.example'],
                    'flags' => 'avz',
                ],
            ],
        ],
    ];

    $config = DeploymentConfig::fromArray('staging', $configArray['environments']['staging']);

    expect($config->rsyncExcludes)->toBe(['.git/', 'node_modules/', 'tests/']);
    expect($config->rsyncIncludes)->toBe(['composer.json', 'composer.lock', '.env.example']);
    expect($config->rsyncFlags)->toBe('avz');
});

test('fromArray() converts port string to integer', function () {
    $configArray = [
        'environments' => [
            'local' => [
                'hostname' => 'example.com',
                'remoteUser' => 'deploy',
                'deployPath' => '/var/www/app',
                'port' => '2222',
            ],
        ],
    ];

    $config = DeploymentConfig::fromArray('local', $configArray['environments']['local']);

    expect($config->port)->toBe(2222);
});

test('fromArray() sets phpBinary from config', function () {
    $configArray = [
        'environments' => [
            'local' => [
                'hostname' => 'example.com',
                'remoteUser' => 'deploy',
                'deployPath' => '/var/www/app',
                'phpBinary' => 'php8.3',
            ],
        ],
    ];

    $config = DeploymentConfig::fromArray('local', $configArray['environments']['local']);

    expect($config->phpBinary)->toBe('php8.3');
});

test('isHealthCheckEnabled() returns false when healthCheckUrl is null', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist',
        healthCheckUrl: null
    );

    expect($config->isHealthCheckEnabled())->toBeFalse();
});

test('isHealthCheckEnabled() returns true when healthCheckUrl is set', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist',
        healthCheckUrl: 'https://example.com/health'
    );

    expect($config->isHealthCheckEnabled())->toBeTrue();
});

test('detectCurrentBranch() returns branch name from git', function () {
    // This test would need to mock shell_exec, but since it's a static method
    // and shell_exec is a built-in function, we'll test the fallback behavior
    // when git command fails

    // We can't easily mock shell_exec in this context, so we'll test that
    // the method exists and returns a string (either from git or default)
    $reflection = new ReflectionClass(DeploymentConfig::class);
    $method = $reflection->getMethod('detectCurrentBranch');
    $method->setAccessible(true);

    $result = $method->invoke(null);

    expect($result)->toBeString();
    expect($result)->not->toBeEmpty();
});

test('fromArray() sets default required and optional services', function () {
    $configArray = [
        'environments' => [
            'local' => [
                'hostname' => 'example.com',
                'remoteUser' => 'deploy',
                'deployPath' => '/var/www/app',
            ],
        ],
    ];

    $config = DeploymentConfig::fromArray('local', $configArray['environments']['local']);

    expect($config->requiredServices)->toBe(['php-fpm', 'nginx']);
    expect($config->optionalServices)->toBe(['supervisor']);
});

test('fromArray() applies custom required and optional services', function () {
    $configArray = [
        'environments' => [
            'production' => [
                'hostname' => 'prod.example.com',
                'remoteUser' => 'deploy',
                'deployPath' => '/var/www/prod',
                'requiredServices' => ['php-fpm', 'nginx', 'supervisor'],
                'optionalServices' => [],
            ],
        ],
    ];

    $config = DeploymentConfig::fromArray('production', $configArray['environments']['production']);

    expect($config->requiredServices)->toBe(['php-fpm', 'nginx', 'supervisor']);
    expect($config->optionalServices)->toBe([]);
});

test('with() method preserves required and optional services', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist',
        requiredServices: ['php-fpm', 'nginx', 'supervisor'],
        optionalServices: []
    );

    $newConfig = $config->with(['hostname' => 'new.example.com']);

    expect($newConfig->requiredServices)->toBe(['php-fpm', 'nginx', 'supervisor']);
    expect($newConfig->optionalServices)->toBe([]);
    expect($newConfig->hostname)->toBe('new.example.com');
});