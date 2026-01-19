<?php

use Shaf\LaravelDeployer\Actions\HealthCheckAction;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Exceptions\HealthCheckException;
use Shaf\LaravelDeployer\Services\CommandService;

beforeEach(function () {
    $this->config = new DeploymentConfig(
        environment: Environment::LOCAL,
        hostname: 'localhost',
        remoteUser: 'deploy',
        deployPath: '/var/www/test',
        composerOptions: '--prefer-dist',
        healthCheckUrl: '/health'
    );

    $this->cmd = Mockery::mock(CommandService::class);

    $this->action = new HealthCheckAction($this->cmd, $this->config);
});

afterEach(function () {
    Mockery::close();
});

test('check() returns true on HTTP 200 response', function () {
    // Mock server resources check
    $this->cmd->shouldReceive('info')
        ->with('Checking server resources...')
        ->once();
    $this->cmd->shouldReceive('remote')
        ->with("echo \"DISK:\$(df -h '/var/www/test' | tail -1 | awk '{print \$5}' | sed 's/%//')\" && echo \"MEM:\$(free | grep Mem | awk '{print int(\$3/\$2 * 100)}')\"")
        ->andReturn("DISK:45\nMEM:60");
    $this->cmd->shouldReceive('info')
        ->with('Disk usage: 45%')
        ->once();
    $this->cmd->shouldReceive('info')
        ->with('Memory usage: 60%')
        ->once();
    $this->cmd->shouldReceive('success')
        ->with('Server resources check passed')
        ->once();

    // Mock endpoint check
    $this->cmd->shouldReceive('info')
        ->with('Checking HTTP endpoints...')
        ->once();
    $this->cmd->shouldReceive('remote')
        ->with("curl -s -o /dev/null -w '%{http_code}' http://localhost/health")
        ->andReturn('200');
    $this->cmd->shouldReceive('success')
        ->with('✓ http://localhost/health returned 200')
        ->once();
    $this->cmd->shouldReceive('success')
        ->with('All endpoint checks passed')
        ->once();
    $this->cmd->shouldReceive('task')
        ->with('health:check')
        ->once();
    $this->cmd->shouldReceive('success')
        ->with('All health checks passed')
        ->once();

    $result = $this->action->check();

    expect($result)->toBeTrue();
});

test('check() returns false on HTTP 500 response', function () {
    // Mock server resources check
    $this->cmd->shouldReceive('info')
        ->with('Checking server resources...')
        ->once();
    $this->cmd->shouldReceive('remote')
        ->with("echo \"DISK:\$(df -h '/var/www/test' | tail -1 | awk '{print \$5}' | sed 's/%//')\" && echo \"MEM:\$(free | grep Mem | awk '{print int(\$3/\$2 * 100)}')\"")
        ->andReturn("DISK:45\nMEM:60");
    $this->cmd->shouldReceive('info')
        ->with('Disk usage: 45%')
        ->once();
    $this->cmd->shouldReceive('info')
        ->with('Memory usage: 60%')
        ->once();
    $this->cmd->shouldReceive('success')
        ->with('Server resources check passed')
        ->once();

    // Mock endpoint check
    $this->cmd->shouldReceive('info')
        ->with('Checking HTTP endpoints...')
        ->once();
    $this->cmd->shouldReceive('remote')
        ->with("curl -s -o /dev/null -w '%{http_code}' http://localhost/health")
        ->andReturn('500');
    $this->cmd->shouldReceive('error')
        ->with('✗ http://localhost/health returned 500 (expected 200)')
        ->once();
    $this->cmd->shouldReceive('error')
        ->with('Some endpoint checks failed')
        ->once();
    $this->cmd->shouldReceive('task')
        ->with('health:check')
        ->once();

    $result = $this->action->check();

    expect($result)->toBeFalse();
});

test('check() validates against expectedStatus from config', function () {
    // Test checkEndpoints with custom status
    $this->cmd->shouldReceive('info')
        ->with('Checking HTTP endpoints...')
        ->once();
    $this->cmd->shouldReceive('remote')
        ->with("curl -s -o /dev/null -w '%{http_code}' http://localhost/health")
        ->andReturn('201');
    $this->cmd->shouldReceive('success')
        ->with('✓ http://localhost/health returned 201')
        ->once();
    $this->cmd->shouldReceive('success')
        ->with('All endpoint checks passed')
        ->once();

    // Test with different expected status (201 instead of 200)
    $endpoints = [['url' => 'http://localhost/health', 'status' => 201]];

    $result = $this->action->checkEndpoints($endpoints);

    expect($result)->toBeTrue();
});

test('verifyDeployment() throws on health check failure', function () {
    $this->cmd->shouldReceive('task')
        ->with('health:verify')
        ->once();
    $this->cmd->shouldReceive('info')
        ->with('Verifying deployment health...')
        ->once();

    // Mock the retry attempts - since retry() makes multiple calls, we need to handle them
    $this->cmd->shouldReceive('info')
        ->with(Mockery::pattern('/→ Attempt \d\/3/'))
        ->times(3); // 3 retry attempts

    $this->cmd->shouldReceive('remote')
        ->with("curl -s -o /dev/null -w '%{http_code}' --max-time 10 'http://localhost/health'")
        ->times(3)
        ->andReturn('500'); // Always fails

    $this->cmd->shouldReceive('warning')
        ->with('  ✗ Health check returned 500, expected 200')
        ->times(3);

    $this->expectException(HealthCheckException::class);

    $this->action->verifyDeployment();
});
