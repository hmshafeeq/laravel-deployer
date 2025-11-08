<?php

use Shaf\LaravelDeployer\Deployer\Deployer;
use Shaf\LaravelDeployer\Deployer\HealthCheckTasks;

beforeEach(function () {
    $this->config = [
        'hostname' => 'localhost',
        'remote_user' => trim(shell_exec('whoami')),
        'deploy_path' => base_path('.deploy/builds'),
        'repository' => base_path(),
        'branch' => 'main',
        'local' => true, // Mark as local to skip SSH
        'health_checks' => [
            'endpoints' => [],
        ],
    ];

    $this->deployer = new Deployer('test', $this->config);
    $this->healthTasks = new HealthCheckTasks($this->deployer);
});

test('health check tasks can be instantiated', function () {
    expect($this->healthTasks)->toBeInstanceOf(HealthCheckTasks::class);
});

test('check resources runs without errors', function () {
    ob_start();
    $this->healthTasks->checkResources();
    $output = ob_get_clean();

    expect($output)->toContain('Checking system resources');
});

test('check endpoints handles empty endpoint list', function () {
    ob_start();
    $this->healthTasks->checkEndpoints();
    $output = ob_get_clean();

    expect($output)->toContain('No health check endpoints configured');
});

test('check endpoints processes configured endpoints', function () {
    $config = array_merge($this->config, [
        'health_checks' => [
            'endpoints' => [
                'https://example.com/health',
            ],
        ],
    ]);

    $deployer = new Deployer('test', $config);
    $healthTasks = new HealthCheckTasks($deployer);

    ob_start();
    $healthTasks->checkEndpoints();
    $output = ob_get_clean();

    expect($output)->toContain('Checking health endpoints');
});
