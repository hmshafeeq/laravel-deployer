<?php

namespace Shaf\LaravelDeployer\Tests\Feature;

test('setup command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deployer:setup');
});

test('setup command can be instantiated', function () {
    $result = $this->artisan('deployer:setup --help');

    expect($result->run())->toBe(0);
});
