<?php

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Exceptions\SSHConnectionException;
use Shaf\LaravelDeployer\Services\SshService;

// ============================================================
// Construction & Factory Methods
// ============================================================

test('fromConfig() creates service with correct properties', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www/app',
        composerOptions: '--prefer-dist',
        port: 2222,
        strictHostKeyChecking: false,
    );

    $service = SshService::fromConfig($config);

    expect($service->getHost())->toBe('example.com');
    expect($service->getUser())->toBe('deploy');
    expect($service->getPort())->toBe(2222);
    expect($service->getTarget())->toBe('deploy@example.com');
});

test('fromConfig() with default port returns null', function () {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www/app',
        composerOptions: '--prefer-dist',
    );

    $service = SshService::fromConfig($config);

    expect($service->getPort())->toBeNull();
});

test('fromArray() creates service from raw config', function () {
    $service = SshService::fromArray([
        'host' => '10.0.0.1',
        'user' => 'root',
        'port' => 22,
        'identityFile' => '/tmp/test_key',
    ]);

    expect($service->getHost())->toBe('10.0.0.1');
    expect($service->getUser())->toBe('root');
    expect($service->getPort())->toBe(22);
});

// ============================================================
// SSH Options Building
// ============================================================

test('buildSshOptions() includes port when set', function () {
    $service = new SshService('host', 'user', port: 2222);

    $options = $service->buildSshOptions();

    expect($options)->toContain('-p');
    expect($options)->toContain('2222');
});

test('buildSshOptions() omits port when null', function () {
    $service = new SshService('host', 'user');

    $options = $service->buildSshOptions();

    expect($options)->not->toContain('-p');
});

test('buildSshOptions() disables strict host key checking when configured', function () {
    $service = new SshService('host', 'user', strictHostKeyChecking: false);

    $options = $service->buildSshOptions();

    expect($options)->toContain('StrictHostKeyChecking=no');
    expect($options)->toContain('UserKnownHostsFile=/dev/null');
});

test('buildSshOptions() omits host key options when strict checking enabled', function () {
    $service = new SshService('host', 'user', strictHostKeyChecking: true);

    $options = $service->buildSshOptions();

    expect($options)->not->toContain('StrictHostKeyChecking=no');
});

test('buildSshOptions() always includes PasswordAuthentication=no', function () {
    $service = new SshService('host', 'user');

    $options = $service->buildSshOptions();

    expect($options)->toContain('PasswordAuthentication=no');
});

test('buildSshOptions() includes multiplexing when enabled', function () {
    $service = new SshService('host', 'user');
    $service->enableMultiplexing();

    $options = $service->buildSshOptions();

    expect($options)->toContain('ControlMaster=auto');
    expect($options)->toContain('ControlPersist=60');
});

test('buildSshOptions() omits multiplexing when disabled', function () {
    $service = new SshService('host', 'user');
    $service->disableMultiplexing();

    $options = $service->buildSshOptions();

    expect($options)->not->toContain('ControlMaster=auto');
});

test('buildSshOptionsString() produces correct format', function () {
    $service = new SshService('host', 'user', port: 2222, strictHostKeyChecking: false);

    $options = $service->buildSshOptionsString();

    expect($options)->toContain('-p 2222');
    expect($options)->toContain('-o StrictHostKeyChecking=no');
    expect($options)->toContain('-o UserKnownHostsFile=/dev/null');
    expect($options)->toContain('-o PasswordAuthentication=no');
});

test('buildSshOptionsString() includes identity file', function () {
    $tmpKey = tempnam(sys_get_temp_dir(), 'ssh_test_');
    file_put_contents($tmpKey, 'test');

    try {
        $service = new SshService('host', 'user', identityFile: $tmpKey);
        $options = $service->buildSshOptionsString();

        expect($options)->toContain('-i '.escapeshellarg($tmpKey));
    } finally {
        @unlink($tmpKey);
    }
});

// ============================================================
// Rsync SSH Options
// ============================================================

test('buildRsyncSshOptions() includes agent forwarding', function () {
    $service = new SshService('host', 'user');

    $options = $service->buildRsyncSshOptions();

    expect($options)->toStartWith('ssh -A');
});

test('buildRsyncSshOptions() includes port when set', function () {
    $service = new SshService('host', 'user', port: 2222);

    $options = $service->buildRsyncSshOptions();

    expect($options)->toContain('-p 2222');
});

test('buildRsyncSshOptions() includes multiplexing when enabled', function () {
    $service = new SshService('host', 'user');
    $service->enableMultiplexing();

    $options = $service->buildRsyncSshOptions();

    expect($options)->toContain('ControlMaster=auto');
    expect($options)->toContain('ControlPersist=60');
});

// ============================================================
// Tilde Expansion
// ============================================================

test('expandTilde() replaces tilde with home directory', function () {
    $service = new SshService('host', 'user');

    $expanded = $service->expandTilde('~/path/to/key');

    expect($expanded)->not->toStartWith('~');
    expect($expanded)->toEndWith('/path/to/key');
});

test('expandTilde() leaves non-tilde paths unchanged', function () {
    $service = new SshService('host', 'user');

    expect($service->expandTilde('/absolute/path'))->toBe('/absolute/path');
    expect($service->expandTilde('relative/path'))->toBe('relative/path');
});

// ============================================================
// Windows Path Conversion
// ============================================================

test('windowsPathToWsl() converts drive letter paths', function () {
    expect(SshService::windowsPathToWsl('C:\\Users\\test\\file.txt'))->toBe('/mnt/c/Users/test/file.txt');
    expect(SshService::windowsPathToWsl('D:\\Projects\\app'))->toBe('/mnt/d/Projects/app');
});

test('windowsPathToWsl() handles forward slashes', function () {
    expect(SshService::windowsPathToWsl('C:/Users/test'))->toBe('/mnt/c/Users/test');
});

test('windowsPathToWsl() leaves Unix paths unchanged', function () {
    expect(SshService::windowsPathToWsl('/home/user/file.txt'))->toBe('/home/user/file.txt');
});

test('windowsPathToWsl() lowercases drive letter', function () {
    expect(SshService::windowsPathToWsl('E:\\data'))->toBe('/mnt/e/data');
});

// ============================================================
// WSL Wrapping
// ============================================================

test('wrapForWsl() returns command unchanged on non-Windows', function () {
    // This test runs on Unix/macOS, so isWindows() returns false
    $command = 'rsync -az /src/ user@host:/dest/';

    expect(SshService::wrapForWsl($command))->toBe($command);
    expect(SshService::wrapForWsl($command, ['/src/']))->toBe($command);
});

// ============================================================
// Configuration
// ============================================================

test('setTimeout() updates timeout', function () {
    $service = new SshService('host', 'user');
    $result = $service->setTimeout(3600);

    expect($result)->toBeInstanceOf(SshService::class);
});

test('enableMultiplexing() returns self for chaining', function () {
    $service = new SshService('host', 'user');
    $result = $service->enableMultiplexing();

    expect($result)->toBeInstanceOf(SshService::class);
});

test('disableMultiplexing() returns self for chaining', function () {
    $service = new SshService('host', 'user');
    $result = $service->disableMultiplexing();

    expect($result)->toBeInstanceOf(SshService::class);
});

test('getTarget() returns user@host format', function () {
    $service = new SshService('example.com', 'deploy');

    expect($service->getTarget())->toBe('deploy@example.com');
});

// ============================================================
// Identity File Validation
// ============================================================

test('buildSshOptions() throws when identity file does not exist', function () {
    $service = new SshService('host', 'user', identityFile: '/nonexistent/key');

    $service->buildSshOptions();
})->throws(SSHConnectionException::class, 'Identity file not found');

test('constructor expands tilde in identity file path', function () {
    $service = new SshService('host', 'user', identityFile: '~/.ssh/id_rsa');

    $identityFile = $service->getIdentityFile();

    expect($identityFile)->not->toStartWith('~');
    expect($identityFile)->toEndWith('.ssh/id_rsa');
});
