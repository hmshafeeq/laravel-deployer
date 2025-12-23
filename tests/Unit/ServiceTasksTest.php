<?php

use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Deployer\ServiceTasks;

beforeEach(function () {
    $this->config = [
        'hostname' => 'localhost',
        'remote_user' => trim(shell_exec('whoami')),
        'deploy_path' => base_path('.deploy/builds'),
        'repository' => base_path(),
        'branch' => 'main',
        'local' => true, // Mark as local to skip SSH
    ];

    $this->deployer = new Deployer('test', $this->config);
    $this->serviceTasks = new ServiceTasks($this->deployer);
});

test('service tasks can be instantiated', function () {
    expect($this->serviceTasks)->toBeInstanceOf(ServiceTasks::class);
});

test('php-fpm restart attempts to detect services', function () {
    ob_start();
    try {
        $this->serviceTasks->restartPhpFpm();
        $output = ob_get_clean();
        expect($output)->toContain('Restarting PHP-FPM');
    } catch (\RuntimeException $e) {
        // Expected in test environment without systemd/sudo
        $output = ob_get_clean();
        expect($e->getMessage())->toContain('Command failed');
    }
})->skip('Requires systemd and sudo access');

test('nginx restart executes systemctl command', function () {
    ob_start();
    try {
        $this->serviceTasks->restartNginx();
        $output = ob_get_clean();
        expect($output)->toContain('Restarting Nginx');
    } catch (\RuntimeException $e) {
        // Expected in test environment without systemd/sudo
        $output = ob_get_clean();
        expect($e->getMessage())->toContain('Command failed');
    }
})->skip('Requires systemd and sudo access');

test('supervisor reload executes supervisorctl command', function () {
    ob_start();
    try {
        $this->serviceTasks->reloadSupervisor();
        $output = ob_get_clean();
        expect($output)->toContain('Reloading Supervisor');
    } catch (\RuntimeException $e) {
        // Expected in test environment without supervisor
        $output = ob_get_clean();
        expect($e->getMessage())->toContain('Command failed');
    }
})->skip('Requires supervisor and sudo access');
