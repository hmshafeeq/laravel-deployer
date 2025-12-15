<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Deployer;

class ArtisanCommandRunner
{
    public function __construct(
        private Deployer $deployer,
        private ?string $phpPath = null
    ) {
        $this->phpPath = $phpPath ?? config('laravel-deployer.php.executable', '/usr/bin/php');
    }

    /**
     * Run an artisan command at the specified path
     */
    public function run(string $command, string $path, bool $showOutput = true): string
    {
        $fullCommand = "{$this->phpPath} {$path}/artisan {$command}";

        if ($showOutput) {
            $this->deployer->writeln("run {$fullCommand}");
        }

        $result = $this->deployer->run($fullCommand);

        if ($showOutput && !empty($result)) {
            foreach (explode("\n", trim($result)) as $line) {
                $this->deployer->writeln($line);
            }
        }

        return $result;
    }

    /**
     * Get artisan version
     */
    public function version(string $path): string
    {
        return $this->cmd('--version', $path, true);
    }

    /**
     * Check if .env file exists and has content
     */
    public function checkEnv(string $path): bool
    {
        $result = $this->deployer->run("if [ -s {$path}/.env ]; then echo +accurate; fi");

        if (!empty($result)) {
            $this->deployer->writeln($result);
            return true;
        }

        return false;
    }

    /**
     * Run a command without showing output
     */
    public function runQuiet(string $command, string $path): string
    {
        return $this->cmd($command, $path, false);
    }
}
