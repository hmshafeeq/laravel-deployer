<?php

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Services\RsyncService;

test('buildRsyncCommand() includes correct base flags from config', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist'
    );

    $service = new RsyncService($config, '/source/path');

    $command = invokePrivateMethod($service, 'buildRsyncCommand', ['/source/', '/dest/']);

    expect($command)->toContain('rsync -rzc');
});

test('buildRsyncCommand() adds --exclude for each exclude pattern', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist',
        rsyncExcludes: ['.git/', 'node_modules/', 'vendor/']
    );

    $service = new RsyncService($config, '/source/path');

    $command = invokePrivateMethod($service, 'buildRsyncCommand', ['/source/', '/dest/']);

    expect($command)->toContain("--exclude='.git/'");
    expect($command)->toContain("--exclude='node_modules/'");
    expect($command)->toContain("--exclude='vendor/'");
});

test('buildRsyncCommand() adds --include for each include pattern', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist',
        rsyncIncludes: ['composer.json', 'composer.lock']
    );

    $service = new RsyncService($config, '/source/path');

    $command = invokePrivateMethod($service, 'buildRsyncCommand', ['/source/', '/dest/']);

    expect($command)->toContain("--include='composer.json'");
    expect($command)->toContain("--include='composer.lock'");
});

test('buildRsyncCommand() includes SSH options when not local', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist',
        isLocal: false
    );

    $service = new RsyncService($config, '/source/path');

    $command = invokePrivateMethod($service, 'buildRsyncCommand', ['/source/', 'user@host:/dest/']);

    expect($command)->toContain("-e 'ssh -A");
    expect($command)->toContain('ControlMaster=auto');
    expect($command)->toContain('ControlPersist=60');
});

test('buildRsyncCommand() omits SSH options for local deployment', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'localhost',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist',
        isLocal: true
    );

    $service = new RsyncService($config, '/source/path');

    $command = invokePrivateMethod($service, 'buildRsyncCommand', ['/source/', '/dest/']);

    expect($command)->not->toContain("-e 'ssh");
});

test('parseRsyncStats() extracts files transferred count', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist'
    );

    $service = new RsyncService($config, '/source/path');

    invokePrivateMethod($service, 'parseRsyncStats', [
        "sent 1,234 bytes received 567 bytes 4,303.73 bytes/sec\ntotal size is 12,345 speedup is 8.90",
    ]);

    expect(invokePrivateMethod($service, 'getTotalBytesTransferred'))->toBe(1234);
});

test('parseRsyncStats() extracts bytes transferred', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist'
    );

    $service = new RsyncService($config, '/source/path');

    invokePrivateMethod($service, 'parseRsyncStats', [
        "sent 9,876 bytes received 123 bytes 1,234.56 bytes/sec\ntotal size is 98,765 speedup is 9.87",
    ]);

    expect(invokePrivateMethod($service, 'getTotalBytesTransferred'))->toBe(9876);
});

test('isActualFileTransfer() returns true for file transfer lines', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist'
    );

    $service = new RsyncService($config, '/source/path');

    expect(invokePrivateMethod($service, 'isActualFileTransfer', ['app/Http/Controllers/UserController.php']))->toBeTrue();
    expect(invokePrivateMethod($service, 'isActualFileTransfer', ['config/database.php']))->toBeTrue();
    expect(invokePrivateMethod($service, 'isActualFileTransfer', ['some/deep/nested/file.js']))->toBeTrue();
});

test('isActualFileTransfer() returns false for stats/summary lines', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist'
    );

    $service = new RsyncService($config, '/source/path');

    expect(invokePrivateMethod($service, 'isActualFileTransfer', ['sent 1,234 bytes received 567 bytes']))->toBeFalse();
    expect(invokePrivateMethod($service, 'isActualFileTransfer', ['total size is 12,345 speedup is 8.90']))->toBeFalse();
    expect(invokePrivateMethod($service, 'isActualFileTransfer', ['Number of files: 42']))->toBeFalse();
    expect(invokePrivateMethod($service, 'isActualFileTransfer', ['Total transferred file size: 1234 bytes']))->toBeFalse();
});

test('isDirectoryLine() identifies directory entries (ends with /)', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist'
    );

    $service = new RsyncService($config, '/source/path');

    expect(invokePrivateMethod($service, 'isDirectoryLine', ['app/']))->toBeTrue();
    expect(invokePrivateMethod($service, 'isDirectoryLine', ['resources/views/']))->toBeTrue();
    expect(invokePrivateMethod($service, 'isDirectoryLine', ['config/app.php']))->toBeFalse();
    expect(invokePrivateMethod($service, 'isDirectoryLine', ['database/migrations/']))->toBeTrue();
});
