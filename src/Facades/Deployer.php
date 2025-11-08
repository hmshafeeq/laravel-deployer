<?php

namespace Shaf\LaravelDeployer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void execute(string|array $actions)
 * @method static string run(string $command, bool $local = false)
 * @method static string runLocally(string $command)
 * @method static bool test(string $condition)
 * @method static void writeln(string $message, string $style = 'info')
 * @method static void task(string $name, callable $callback)
 * @method static void set(string $key, $value)
 * @method static mixed get(string $key, $default = null)
 * @method static void loadEnvironment()
 * @method static bool confirmDeployment(bool $skipConfirm = false)
 * @method static string generateReleaseName()
 * @method static string getReleaseName()
 * @method static string getDeployPath()
 * @method static string getCurrentPath()
 * @method static string getReleasePath()
 * @method static string getSharedPath()
 * @method static void setRsyncExcludes(array $excludes)
 * @method static void setRsyncIncludes(array $includes)
 * @method static void runRsync()
 * @method static string runLocalCommand(string $command, bool $showOutput = true)
 * @method static bool isLocal()
 *
 * @see \Shaf\LaravelDeployer\Deployer
 */
class Deployer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'deployer';
    }
}
