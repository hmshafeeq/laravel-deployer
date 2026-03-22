<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    // Set up test deployment configuration
    $this->deployPath = base_path('.deploy');
    $this->buildPath = $this->deployPath.'/builds';
    $this->backupsDir = base_path('.deploy/downloads/backups');

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

afterEach(function () {
    // Clean up backups directory if it exists
    if (isset($this->backupsDir) && File::exists($this->backupsDir)) {
        File::deleteDirectory($this->backupsDir);
    }
});

test('unified database command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deployer:db');
});

test('db command shows usage for invalid action', function () {
    $this->artisan('deployer:db', ['action' => 'invalid'])
        ->assertFailed();
});

test('db list requires backups directory', function () {
    // Remove any existing backups directory
    if (File::exists($this->backupsDir)) {
        File::deleteDirectory($this->backupsDir);
    }

    $this->artisan('deployer:db', ['action' => 'list'])
        ->assertFailed();
});

test('db install requires backup directory', function () {
    // Remove any existing backups directory
    if (File::exists($this->backupsDir)) {
        File::deleteDirectory($this->backupsDir);
    }

    $this->artisan('deployer:db', ['action' => 'install'])
        ->assertFailed();
});

test('db install validates backup file exists', function () {
    // Create backups directory with a valid backup
    File::ensureDirectoryExists($this->backupsDir);
    file_put_contents($this->backupsDir.'/db_backup_test.sql.gz', 'dummy');

    // Try to install non-existent backup by name - should fail
    $this->artisan('deployer:db', ['action' => 'install', 'target' => 'nonexistent.sql.gz'])
        ->assertFailed();
});
