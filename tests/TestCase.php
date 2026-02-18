<?php

namespace Shaf\LaravelDeployer\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Shaf\LaravelDeployer\LaravelDeployerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clean up test deployment before each test
        $this->cleanupTestDeployment();
    }

    protected function tearDown(): void
    {
        // Clean up test deployment after each test
        $this->cleanupTestDeployment();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelDeployerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function cleanupTestDeployment(): void
    {
        $buildPath = base_path('.deploy/builds');

        if (is_dir($buildPath)) {
            shell_exec("rm -rf {$buildPath}");
        }
    }

    protected function createTestDeployConfig(string $environment = 'test'): void
    {
        $deployPath = base_path('.deploy');
        $buildPath = "{$deployPath}/builds";

        // Create directories
        if (! is_dir($deployPath)) {
            mkdir($deployPath, 0755, true);
        }

        if (! is_dir($buildPath)) {
            mkdir($buildPath, 0755, true);
        }

        // Create deploy.json
        $config = [
            'keepReleases' => 3,
            'environments' => [
                $environment => [
                    'local' => true,
                    'deployPath' => $buildPath,
                ],
            ],
            'rsync' => [
                'exclude' => ['.git/', 'node_modules/', 'vendor/'],
                'include' => ['composer.json', 'composer.lock'],
            ],
        ];

        file_put_contents("{$deployPath}/deploy.json", json_encode($config, JSON_PRETTY_PRINT));
    }

    protected function getCurrentUser(): string
    {
        return trim(shell_exec('whoami') ?? 'root');
    }

    protected function getTestDeployPath(): string
    {
        return base_path('.deploy/builds');
    }
}
