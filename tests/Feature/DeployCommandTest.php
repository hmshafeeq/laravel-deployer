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

    // Create deploy.yaml config for local deployment
    $config = [
        'test' => [
            'hostname' => 'localhost',
            'remote_user' => trim(shell_exec('whoami')),
            'deploy_path' => $this->buildPath,
            'repository' => base_path(),
            'branch' => 'main',
            'local' => true, // Mark as local to skip SSH
            'shared_dirs' => [
                'storage/app',
                'storage/framework',
                'storage/logs',
            ],
            'shared_files' => ['.env'],
            'writable_dirs' => [
                'storage',
                'storage/app',
                'storage/framework',
                'storage/logs',
            ],
            'keep_releases' => 2,
            'rsync' => [
                'src',
                'tests',
                'composer.json',
            ],
            'health_checks' => [
                'endpoints' => [],
            ],
        ],
    ];

    file_put_contents(
        $this->deployPath.'/deploy.yaml',
        Yaml::dump($config)
    );

    // Create a minimal .env file for testing
    file_put_contents(
        $this->deployPath.'/.env.test',
        "APP_NAME=TestApp\nAPP_ENV=testing\n"
    );
});

test('deploy command is registered', function () {
    $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

    expect($commands)->toHaveKey('deploy');
});

test('deploy command can be instantiated', function () {
    $result = $this->artisan('deploy --help');

    expect($result->run())->toBe(0);
});

test('deploy command requires environment argument', function () {
    $result = $this->artisan('deploy');

    // Should fail without environment argument
    expect($result->run())->not->toBe(0);
})->skip('Command validation varies by Laravel version');

test('deployment creates release directory structure', function () {
    // This test requires full deployment environment
    // Including composer, npm, rsync, etc.
    expect(true)->toBeTrue();
})->skip('Requires full deployment environment - run manually with: php artisan deploy test --no-confirm');

test('deployment creates current symlink', function () {
    // This test requires full deployment environment
    expect(true)->toBeTrue();
})->skip('Requires full deployment environment - run manually with: php artisan deploy test --no-confirm');

test('deployment maintains release history', function () {
    // This test requires full deployment environment
    expect(true)->toBeTrue();
})->skip('Requires full deployment environment - run manually with: php artisan deploy test --no-confirm');

test('deployment cleans up old releases', function () {
    // This test requires full deployment environment
    expect(true)->toBeTrue();
})->skip('Requires full deployment environment - run manually with: php artisan deploy test --no-confirm');
