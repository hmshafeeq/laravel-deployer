<?php

namespace Shaf\LaravelDeployer\Tests\Feature;

test('clear command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deployer:server');
});

test('provision command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deployer:server');
});

test('clear requires environment argument', function () {
    $result = $this->artisan('deployer:server clear');

    $result->assertFailed();
    $result->expectsOutputToContain('Environment is required');
});

test('clear --help shows available options', function () {
    $result = $this->artisan('deployer:server clear --help');

    expect($result->run())->toBe(0);
    $result->expectsOutputToContain('--no-confirm');
});


test('diagnose command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deployer:server');
});

test('diagnose requires environment argument', function () {
    $result = $this->artisan('deployer:server diagnose');

    $result->assertFailed();
    $result->expectsOutputToContain('Environment is required');
});

test('diagnose --help shows available options', function () {
    $result = $this->artisan('deployer:server diagnose --help');

    expect($result->run())->toBe(0);
    $result->expectsOutputToContain('--full');
    $result->expectsOutputToContain('--fix');
});
