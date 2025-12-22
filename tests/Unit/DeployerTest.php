<?php

use Shaf\LaravelDeployer\Deployer\Deployer;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    // Create test deploy config
    $this->deployPath = base_path('.deploy');
    $this->buildPath = $this->deployPath.'/builds';

    if (! is_dir($this->deployPath)) {
        mkdir($this->deployPath, 0755, true);
    }

    // Copy fixture config
    copy(
        __DIR__.'/../Fixtures/deploy.yaml',
        $this->deployPath.'/deploy.yaml'
    );

    $yamlConfig = Yaml::parseFile($this->deployPath.'/deploy.yaml');
    $this->config = $yamlConfig['test'];
    // Mark as local for testing
    $this->config['local'] = true;
    $this->config['hostname'] = 'localhost';
    $this->config['remote_user'] = trim(shell_exec('whoami'));
});

test('deployer can be instantiated with config', function () {
    $deployer = new Deployer('test', $this->config);

    expect($deployer)->toBeInstanceOf(Deployer::class);
});

test('deployer can run local commands', function () {
    $deployer = new Deployer('test', $this->config);

    $result = $deployer->runLocally('echo "Hello World"');

    expect($result)->toBe('Hello World');
});

test('deployer can generate release names', function () {
    $deployer = new Deployer('test', $this->config);
    $deployer->loadEnvironment();

    $releaseName = $deployer->generateReleaseName();

    expect($releaseName)
        ->toBeString()
        ->toMatch('/^\d{6}\.\d+$/'); // Format: YYYYMM.N
});

test('deployer outputs formatted messages', function () {
    $deployer = new Deployer('test', $this->config);

    ob_start();
    $deployer->writeln('Test message', 'info');
    $output = ob_get_clean();

    expect($output)->toContain('[test]');
});

test('deployer can confirm deployment', function () {
    $deployer = new Deployer('test', $this->config);

    // Test with skip confirm
    $result = $deployer->confirmDeployment(true);

    expect($result)->toBeTrue();
});

test('deployer handles local environment correctly', function () {
    $deployer = new Deployer('test', $this->config);

    expect($deployer->isLocal())->toBeTrue(); // Based on config
});
