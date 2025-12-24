<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    // Set up test deployment configuration
    $this->deployPath = base_path('.deploy');
    $this->buildPath = $this->deployPath.'/builds';

    if (! is_dir($this->deployPath)) {
        mkdir($this->deployPath, 0755, true);
    }

    // Create deploy.json config
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

    // Create test .env file with database config and deploy secrets
    $envContent = <<<'ENV'
DEPLOY_HOST=localhost
DEPLOY_USER=test_user
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=test_database
DB_USERNAME=test_user
DB_PASSWORD=test_password
ENV;

    file_put_contents($this->deployPath.'/.env.test', $envContent);
});

test('database backup command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('database:backup');
});

test('database download command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('database:download');
});

test('database upload command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('database:upload');
});

test('database restore command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('database:restore');
});

test('database restore command validates backup file exists', function () {
    $nonExistentFile = '/tmp/nonexistent_backup.sql.gz';

    $this->artisan('database:restore', ['backup' => $nonExistentFile])
        ->assertFailed();
});
