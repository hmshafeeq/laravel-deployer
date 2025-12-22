<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    // Set up test deployment configuration
    $this->deployPath = base_path('.deploy');
    $this->buildPath = $this->deployPath.'/builds';

    if (! is_dir($this->deployPath)) {
        mkdir($this->deployPath, 0755, true);
    }

    // Create deploy.yaml config with correct keys
    $config = [
        'test' => [
            'hostname' => 'localhost',
            'remote_user' => trim(shell_exec('whoami')),
            'deploy_path' => $this->buildPath,
            'repository' => base_path(),
            'branch' => 'main',
            'local' => true, // Mark as local to skip SSH
            'shared_dirs' => ['storage'],
            'shared_files' => ['.env'],
            'writable_dirs' => ['storage'],
            'keep_releases' => 2,
            'rsync' => ['src', 'tests'],
            'health_checks' => ['endpoints' => []],
        ],
    ];

    file_put_contents(
        $this->deployPath.'/deploy.yaml',
        Yaml::dump($config)
    );

    // Create test .env file with database config
    $envContent = <<<'ENV'
APP_NAME=TestApp
APP_ENV=testing
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
