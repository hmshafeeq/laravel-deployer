<?php

namespace Shaf\LaravelDeployer\Tests\Feature;

use Shaf\LaravelDeployer\Tests\TestCase;

test('rollback command is registered in artisan', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deployer:release');
});

test('rollback --help returns exit code 0', function () {
    $result = $this->artisan('deployer:release rollback --help');

    expect($result->run())->toBe(0);
});

test('rollback requires environment argument', function () {
    $this->expectException(\RuntimeException::class);
    $this->artisan('deployer:release rollback');
});

test('rollback shows error for invalid environment', function () {
    $result = $this->artisan('deployer:release rollback invalid-env');

    $result->assertFailed();
    $result->expectsOutputToContain('not found');
})->skip('Requires actual deployment configuration to test properly');