<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    // Set up test deployment configuration
    $this->deployPath = base_path('.deploy');
    $this->buildPath = $this->deployPath.'/builds';

    if (! is_dir($this->deployPath)) {
        mkdir($this->deployPath, 0755, true);
    }

    // Create deploy.json config for local deployment
    $config = [
        'keepReleases' => 2,
        'environments' => [
            'test' => [
                'local' => true,
                'deployPath' => $this->buildPath,
            ],
        ],
        'rsync' => [
            'exclude' => ['.git/', 'node_modules/', 'vendor/'],
            'include' => ['composer.json', 'composer.lock'],
        ],
    ];

    file_put_contents(
        $this->deployPath.'/deploy.json',
        json_encode($config, JSON_PRETTY_PRINT)
    );

    // Create a minimal .env file for testing
    file_put_contents(
        $this->deployPath.'/.env.test',
        "DEPLOY_HOST=localhost\nDEPLOY_USER=".trim(shell_exec('whoami'))."\n"
    );
});

test('deployer:release command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deployer:release');
});

test('deployer:release command can be instantiated', function () {
    $result = $this->artisan('deployer:release --help');

    expect($result->run())->toBe(0);
});

test('deployer list command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deployer');
});

test('deployer:sync command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deployer:sync');
});

test('deployer:sync has --dirty option', function () {
    $command = $this->app->make('Illuminate\Contracts\Console\Kernel')->all()['deployer:sync'];

    $definition = $command->getDefinition();

    expect($definition->hasOption('dirty'))->toBeTrue();
    expect($definition->getOption('dirty')->acceptValue())->toBeFalse();
});

test('deployer:sync has --since option', function () {
    $command = $this->app->make('Illuminate\Contracts\Console\Kernel')->all()['deployer:sync'];

    $definition = $command->getDefinition();

    expect($definition->hasOption('since'))->toBeTrue();
    expect($definition->getOption('since')->acceptValue())->toBeTrue();
});

test('deployer:sync has --branch option', function () {
    $command = $this->app->make('Illuminate\Contracts\Console\Kernel')->all()['deployer:sync'];

    $definition = $command->getDefinition();

    expect($definition->hasOption('branch'))->toBeTrue();
});
