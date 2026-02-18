<?php

namespace Shaf\LaravelDeployer\Tests\Feature;

test('rollback command is registered in artisan', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deployer:rollback');
});

test('rollback --help returns exit code 0', function () {
    $result = $this->artisan('deployer:rollback --help');

    expect($result->run())->toBe(0);
});

test('rollback requires environment argument', function () {
    $this->expectException(\RuntimeException::class);
    $this->artisan('deployer:rollback');
});
