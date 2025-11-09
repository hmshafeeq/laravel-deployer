<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Constants\Commands;
use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Exceptions\TaskExecutionException;

class ArtisanTaskRunner
{
    public function __construct(
        private CommandExecutor $executor,
        private OutputService $output,
        private string $releasePath,
        private string $phpBinary = Commands::PHP_BINARY,
    ) {}

    public function run(string $command, array $options = [], bool $force = false): string
    {
        $optionsString = $this->buildOptionsString($options, $force);
        $fullCommand = "{$this->phpBinary} {$this->releasePath}/artisan {$command}{$optionsString}";

        $this->output->info("Running artisan {$command}");

        try {
            return $this->executor->execute($fullCommand);
        } catch (\Exception $e) {
            throw TaskExecutionException::artisanFailed($command, $e->getMessage());
        }
    }

    public function version(): string
    {
        $fullCommand = "{$this->phpBinary} {$this->releasePath}/artisan --version";
        return $this->executor->execute($fullCommand);
    }

    public function storageLink(): string
    {
        return $this->run('storage:link');
    }

    public function configCache(): string
    {
        return $this->run('config:cache');
    }

    public function configClear(): string
    {
        return $this->run('config:clear');
    }

    public function viewCache(): string
    {
        return $this->run('view:cache');
    }

    public function viewClear(): string
    {
        return $this->run('view:clear');
    }

    public function routeCache(): string
    {
        return $this->run('route:cache');
    }

    public function routeClear(): string
    {
        return $this->run('route:clear');
    }

    public function optimize(): string
    {
        return $this->run('optimize');
    }

    public function optimizeClear(): string
    {
        return $this->run('optimize:clear');
    }

    public function migrate(bool $force = true): string
    {
        return $this->run('migrate', force: $force);
    }

    public function queueRestart(): string
    {
        return $this->run('queue:restart');
    }

    public function down(bool $refresh = true, int $retry = 60): string
    {
        $options = ['refresh' => $refresh ? '15' : '0', 'retry' => (string) $retry];
        return $this->run('down', $options);
    }

    public function up(): string
    {
        return $this->run('up');
    }

    private function buildOptionsString(array $options, bool $force): string
    {
        $parts = [];

        if ($force) {
            $parts[] = '--force';
        }

        foreach ($options as $key => $value) {
            if (is_int($key)) {
                // It's a flag without value
                $parts[] = $value;
            } elseif ($value === true) {
                // Boolean true => flag
                $parts[] = "--{$key}";
            } elseif ($value !== false && $value !== null) {
                // Key=value option
                $parts[] = "--{$key}={$value}";
            }
        }

        return empty($parts) ? '' : ' ' . implode(' ', $parts);
    }
}
