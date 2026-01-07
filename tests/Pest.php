<?php

use Shaf\LaravelDeployer\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function cleanupTestDeployment(): void
{
    $buildPath = base_path('.deploy/builds');

    if (is_dir($buildPath)) {
        shell_exec("rm -rf {$buildPath}");
    }
}

/**
 * Invoke a private/protected method on an object for testing
 */
function invokePrivateMethod(object $object, string $method, array $args = []): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $args);
}
