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
