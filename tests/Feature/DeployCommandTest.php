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

test('deploy command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deployer');
});

test('deploy command can be instantiated', function () {
    $result = $this->artisan('deployer --help');

    expect($result->run())->toBe(0);
});
