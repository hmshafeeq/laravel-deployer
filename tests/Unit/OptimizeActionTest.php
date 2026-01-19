<?php

use Shaf\LaravelDeployer\Actions\OptimizeAction;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Services\CommandService;

beforeEach(function () {
    $this->cmdMock = Mockery::mock(CommandService::class);
    $this->config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www/app',
        composerOptions: '--prefer-dist'
    );
});

test('service restart fails deployment when required service fails', function () {
    // Mock command service to return failure for nginx
    $this->cmdMock->shouldReceive('info')->atLeast()->once();
    $this->cmdMock->shouldReceive('remote')
        ->with('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""')
        ->andReturn('php8.3-fpm');
    $this->cmdMock->shouldReceive('remote')
        ->with(Mockery::pattern('/sudo systemctl restart php8.3-fpm/'))
        ->andReturn('PHP8_3_FPM_OK ; NGINX_FAIL ; SUPERVISOR_OK');

    $config = $this->config->with([
        'requiredServices' => ['php-fpm', 'nginx'],
        'optionalServices' => ['supervisor'],
    ]);

    $action = new OptimizeAction($this->cmdMock, $config);

    // Use reflection to access private method
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('restartServices');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($action))
        ->toThrow(RuntimeException::class, 'Required service nginx failed to reload');
});

test('service restart continues when optional service fails', function () {
    // Mock command service to return failure for supervisor (no probe output = generic error)
    $this->cmdMock->shouldReceive('info')->atLeast()->once();
    $this->cmdMock->shouldReceive('remote')
        ->with('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""')
        ->andReturn('php8.3-fpm');
    $this->cmdMock->shouldReceive('remote')
        ->with(Mockery::pattern('/sudo systemctl restart php8.3-fpm/'))
        ->andReturn('PHP8_3_FPM_OK ; NGINX_OK ; SUPERVISOR_FAIL');
    $this->cmdMock->shouldReceive('warning')->with('  ⚠  Supervisor reload failed')->once();
    $this->cmdMock->shouldReceive('comment')->with('    Tip: Check if supervisor is running: sudo systemctl status supervisor')->once();
    $this->cmdMock->shouldReceive('success')->with('Service restart completed')->once();

    $config = $this->config->with([
        'requiredServices' => ['php-fpm', 'nginx'],
        'optionalServices' => ['supervisor'],
    ]);

    $action = new OptimizeAction($this->cmdMock, $config);

    // Use reflection to access private method
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('restartServices');
    $method->setAccessible(true);

    // Should not throw
    $method->invoke($action);

    expect(true)->toBeTrue();
});

test('service restart shows detailed error when supervisor probe finds issue', function () {
    // Mock command service to return failure with probe output showing directory error
    $this->cmdMock->shouldReceive('info')->atLeast()->once();
    $this->cmdMock->shouldReceive('remote')
        ->with('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""')
        ->andReturn('php8.3-fpm');

    // Simulate probe output with actual error
    $probeOutput = "PHP8_3_FPM_OK\nNGINX_OK\nSUPERVISOR_PROBE_START\nError: The directory named as part of the path /var/www/app/storage/logs/queue.log does not exist in section 'program:app' (file: '/etc/supervisor/conf.d/app.conf')\nSUPERVISOR_PROBE_END\nSUPERVISOR_FAIL";

    $this->cmdMock->shouldReceive('remote')
        ->with(Mockery::pattern('/sudo systemctl restart php8.3-fpm/'))
        ->andReturn($probeOutput);
    $this->cmdMock->shouldReceive('warning')
        ->with(Mockery::pattern('/Supervisor reload failed.*directory.*does not exist/'))
        ->once();
    $this->cmdMock->shouldReceive('comment')->atLeast()->once(); // Tips shown
    $this->cmdMock->shouldReceive('success')->with('Service restart completed')->once();

    $config = $this->config->with([
        'requiredServices' => ['php-fpm', 'nginx'],
        'optionalServices' => ['supervisor'],
    ]);

    $action = new OptimizeAction($this->cmdMock, $config);

    // Use reflection to access private method
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('restartServices');
    $method->setAccessible(true);

    // Should not throw - supervisor is optional
    $method->invoke($action);

    expect(true)->toBeTrue();
});

test('service restart normalizes php-fpm service names', function () {
    // Mock command service to return failure for php8.3-fpm
    $this->cmdMock->shouldReceive('info')->atLeast()->once();
    $this->cmdMock->shouldReceive('remote')
        ->with('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""')
        ->andReturn('php8.3-fpm');
    $this->cmdMock->shouldReceive('remote')
        ->with(Mockery::pattern('/sudo systemctl restart php8.3-fpm/'))
        ->andReturn('PHP8_3_FPM_FAIL ; NGINX_OK ; SUPERVISOR_OK');

    $config = $this->config->with([
        'requiredServices' => ['php-fpm', 'nginx'],
        'optionalServices' => ['supervisor'],
    ]);

    $action = new OptimizeAction($this->cmdMock, $config);

    // Use reflection to access private method
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('restartServices');
    $method->setAccessible(true);

    // Should throw because php8.3-fpm normalizes to php-fpm which is required
    expect(fn () => $method->invoke($action))
        ->toThrow(RuntimeException::class, 'Required service php8.3-fpm failed to restart');
});

test('service restart succeeds when all required services succeed', function () {
    // Mock command service to return success for all services
    $this->cmdMock->shouldReceive('info')->atLeast()->once();
    $this->cmdMock->shouldReceive('remote')
        ->with('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""')
        ->andReturn('php8.3-fpm');
    $this->cmdMock->shouldReceive('remote')
        ->with(Mockery::pattern('/sudo systemctl restart php8.3-fpm/'))
        ->andReturn('PHP8_3_FPM_OK ; NGINX_OK ; SUPERVISOR_OK');
    $this->cmdMock->shouldReceive('success')->with('Service restart completed')->once();

    $config = $this->config->with([
        'requiredServices' => ['php-fpm', 'nginx'],
        'optionalServices' => ['supervisor'],
    ]);

    $action = new OptimizeAction($this->cmdMock, $config);

    // Use reflection to access private method
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('restartServices');
    $method->setAccessible(true);

    // Should not throw
    $method->invoke($action);

    expect(true)->toBeTrue();
});

test('service restart handles supervisor as required service', function () {
    // Mock command service to return failure for supervisor
    $this->cmdMock->shouldReceive('info')->atLeast()->once();
    $this->cmdMock->shouldReceive('remote')
        ->with('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""')
        ->andReturn('php8.3-fpm');
    $this->cmdMock->shouldReceive('remote')
        ->with(Mockery::pattern('/sudo systemctl restart php8.3-fpm/'))
        ->andReturn('PHP8_3_FPM_OK ; NGINX_OK ; SUPERVISOR_FAIL');

    $config = $this->config->with([
        'requiredServices' => ['php-fpm', 'nginx', 'supervisor'],
        'optionalServices' => [],
    ]);

    $action = new OptimizeAction($this->cmdMock, $config);

    // Use reflection to access private method
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('restartServices');
    $method->setAccessible(true);

    // Should throw because supervisor is now required
    expect(fn () => $method->invoke($action))
        ->toThrow(RuntimeException::class, 'Required service supervisor failed to reload');
});

test('setPostDeployCommands() returns self for chaining', function () {
    $action = new OptimizeAction($this->cmdMock, $this->config);

    $result = $action->setPostDeployCommands(['filament:optimize']);

    expect($result)->toBe($action);
});

test('runPostDeployCommands() executes artisan shortcuts correctly', function () {
    // Mock command service calls
    $this->cmdMock->shouldReceive('info')
        ->with('Running post-deploy commands (with fresh OPcache)...')
        ->once();
    $this->cmdMock->shouldReceive('info')
        ->with('  → artisan filament:optimize')
        ->once();
    $this->cmdMock->shouldReceive('remoteWithOutput')
        ->with('php /var/www/app/current/artisan filament:optimize')
        ->once()
        ->andReturn('Filament optimized!');
    $this->cmdMock->shouldReceive('success')
        ->with('Post-deploy commands completed')
        ->once();

    $action = new OptimizeAction($this->cmdMock, $this->config);
    $action->setPostDeployCommands(['filament:optimize']);

    // Use reflection to access private method
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('runPostDeployCommands');
    $method->setAccessible(true);

    $method->invoke($action, '/var/www/app/current');

    expect(true)->toBeTrue();
});

test('runPostDeployCommands() executes full shell commands correctly', function () {
    // Mock command service calls
    $this->cmdMock->shouldReceive('info')
        ->with('Running post-deploy commands (with fresh OPcache)...')
        ->once();
    $this->cmdMock->shouldReceive('info')
        ->with('  → npm run build')
        ->once();
    $this->cmdMock->shouldReceive('remoteWithOutput')
        ->with(Mockery::pattern('/cd.*&& npm run build/'))
        ->once()
        ->andReturn('Build completed!');
    $this->cmdMock->shouldReceive('success')
        ->with('Post-deploy commands completed')
        ->once();

    $action = new OptimizeAction($this->cmdMock, $this->config);
    $action->setPostDeployCommands(['npm run build']);

    // Use reflection to access private method
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('runPostDeployCommands');
    $method->setAccessible(true);

    $method->invoke($action, '/var/www/app/current');

    expect(true)->toBeTrue();
});

test('runPostDeployCommands() warns but continues on failure', function () {
    // Mock command service calls - command will fail
    $this->cmdMock->shouldReceive('info')
        ->with('Running post-deploy commands (with fresh OPcache)...')
        ->once();
    $this->cmdMock->shouldReceive('info')
        ->with('  → artisan filament:optimize')
        ->once();
    $this->cmdMock->shouldReceive('remoteWithOutput')
        ->andThrow(new \RuntimeException('Command failed'));
    $this->cmdMock->shouldReceive('warning')
        ->with('  ⚠ Command failed: Command failed')
        ->once();
    $this->cmdMock->shouldReceive('success')
        ->with('Post-deploy commands completed')
        ->once();

    $action = new OptimizeAction($this->cmdMock, $this->config);
    $action->setPostDeployCommands(['filament:optimize']);

    // Use reflection to access private method
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('runPostDeployCommands');
    $method->setAccessible(true);

    // Should not throw - failures are warnings
    $method->invoke($action, '/var/www/app/current');

    expect(true)->toBeTrue();
});

test('isArtisanShortcut() identifies artisan commands correctly', function () {
    $action = new OptimizeAction($this->cmdMock, $this->config);

    // Use reflection to access private method
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('isArtisanShortcut');
    $method->setAccessible(true);

    // Artisan shortcuts (no spaces)
    expect($method->invoke($action, 'filament:optimize'))->toBeTrue();
    expect($method->invoke($action, 'cache:clear'))->toBeTrue();
    expect($method->invoke($action, 'optimize'))->toBeTrue();
    expect($method->invoke($action, 'migrate'))->toBeTrue();

    // Full commands (contain spaces)
    expect($method->invoke($action, 'php artisan migrate'))->toBeFalse();
    expect($method->invoke($action, 'npm run build'))->toBeFalse();
    expect($method->invoke($action, 'composer install'))->toBeFalse();
});
