<?php

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\HooksService;

beforeEach(function () {
    $this->config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist'
    );

    $this->cmd = Mockery::mock(CommandService::class);
    $this->cmd->shouldReceive('task')->byDefault();
    $this->cmd->shouldReceive('info')->byDefault();
    $this->cmd->shouldReceive('success')->byDefault();
    $this->cmd->shouldReceive('warning')->byDefault();

    $this->service = new HooksService($this->cmd, $this->config);
});

afterEach(function () {
    Mockery::close();
});

test('run() executes configured commands for hook point', function () {
    $hooks = [
        'after:deploy' => [
            'php artisan cache:clear',
            'npm run build',
        ],
    ];

    $this->service->loadHooks($hooks);
    $this->service->setReleasePath('/var/www/releases/1');

    $this->cmd->shouldReceive('remote')
        ->with("cd '/var/www/releases/1' && php artisan cache:clear")
        ->once();

    $this->cmd->shouldReceive('remote')
        ->with("cd '/var/www/releases/1' && npm run build")
        ->once();

    $this->service->run('after:deploy');
});

test('run() handles artisan shortcut commands', function () {
    $hooks = [
        'after:deploy' => ['artisan view:cache'],
    ];

    $this->service->loadHooks($hooks);
    $this->service->setReleasePath('/var/www/releases/1');

    $this->cmd->shouldReceive('artisan')
        ->with('view:cache', '/var/www/releases/1')
        ->once();

    $this->service->run('after:deploy');
});

test('run() handles local commands', function () {
    $hooks = [
        'before:deploy' => ['local:git fetch'],
    ];

    $this->service->loadHooks($hooks);

    $this->cmd->shouldReceive('local')
        ->with('git fetch')
        ->once();

    $this->service->run('before:deploy');
});

test('critical hooks throw exception on failure', function () {
    $hooks = [
        'before:deploy' => ['local:test'],
    ];

    $this->service->loadHooks($hooks);

    $this->cmd->shouldReceive('local')
        ->andThrow(new Exception('Command failed'));

    expect(fn () => $this->service->run('before:deploy'))
        ->toThrow(Exception::class, 'Command failed');
});

test('non-critical hooks log warning on failure but continue', function () {
    $hooks = [
        'after:deploy' => ['local:fail'],
    ];

    $this->service->loadHooks($hooks);

    $this->cmd->shouldReceive('local')
        ->andThrow(new Exception('Optional command failed'));

    $this->cmd->shouldReceive('warning')
        ->with(Mockery::pattern('/Hook failed/'))
        ->once();

    // Should not throw
    $this->service->run('after:deploy');
});

test('hasHooks() returns true only if hooks exist for point', function () {
    $hooks = ['before:deploy' => ['cmd']];
    $this->service->loadHooks($hooks);

    expect($this->service->hasHooks('before:deploy'))->toBeTrue();
    expect($this->service->hasHooks('after:deploy'))->toBeFalse();
});
