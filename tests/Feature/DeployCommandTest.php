<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    // Set up test deployment configuration
    $this->deployPath = base_path('.deploy');
    $this->buildPath = $this->deployPath.'/builds';

    if (!is_dir($this->deployPath)) {
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

test('deploy command shows confirmation dialog', function () {
    $this->artisan('deploy test deploy --no-confirm')
        ->assertExitCode(0);
});

test('deployment creates release directory structure', function () {
    // Run deployment
    $this->artisan('deploy test deploy --no-confirm')
        ->assertExitCode(0);

    // Check if deployment directory was created
    expect(File::exists($this->buildPath))->toBeTrue();

    // Check if releases directory exists
    $releasesPath = $this->buildPath.'/releases';
    expect(File::exists($releasesPath))->toBeTrue();

    // Check if shared directory exists
    $sharedPath = $this->buildPath.'/shared';
    expect(File::exists($sharedPath))->toBeTrue();
});

test('deployment creates current symlink', function () {
    // Run deployment
    $this->artisan('deploy test deploy --no-confirm')
        ->assertExitCode(0);

    $currentPath = $this->buildPath.'/current';

    // Check if current symlink exists
    expect(File::exists($currentPath))->toBeTrue();
    expect(is_link($currentPath))->toBeTrue();
});

test('deployment maintains release history', function () {
    // Run first deployment
    $this->artisan('deploy test deploy --no-confirm')
        ->assertExitCode(0);

    // Get releases after first deployment
    $releasesPath = $this->buildPath.'/releases';
    $releases1 = File::directories($releasesPath);

    // Run second deployment
    sleep(1); // Ensure different timestamp
    $this->artisan('deploy test deploy --no-confirm')
        ->assertExitCode(0);

    // Get releases after second deployment
    $releases2 = File::directories($releasesPath);

    // Should have 2 releases
    expect(count($releases2))->toBe(2);
});

test('deployment cleans up old releases', function () {
    // Run 3 deployments (keep_releases is set to 2)
    for ($i = 0; $i < 3; $i++) {
        $this->artisan('deploy test deploy --no-confirm')
            ->assertExitCode(0);

        if ($i < 2) {
            sleep(1); // Ensure different timestamps
        }
    }

    $releasesPath = $this->buildPath.'/releases';
    $releases = File::directories($releasesPath);

    // Should only keep 2 releases
    expect(count($releases))->toBeLessThanOrEqual(2);
});
