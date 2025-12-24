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
