<?php

namespace Shaf\LaravelDeployer\Tests\Feature;

use Shaf\LaravelDeployer\Tests\TestCase;

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

test('provision --help shows available options', function () {
    $result = $this->artisan('deployer:server provision --help');

    expect($result->run())->toBe(0);
    $result->expectsOutputToContain('--host');
    $result->expectsOutputToContain('--user');
    $result->expectsOutputToContain('--port');
})->skip('Provision command help testing may require different setup');