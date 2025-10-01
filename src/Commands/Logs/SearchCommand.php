<?php

namespace Shaf\LaravelDeployer\Commands\Logs;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SearchCommand extends Command
{
    protected $signature = 'deployer:logs:search
                            {environment=staging : The deployment environment (local, staging, production)}
                            {--search= : Search term (required)}
                            {--lines=100 : Number of lines to display}
                            {--logfile= : Specific log file to search}';

    protected $description = 'Search application logs for specific terms';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $search = $this->option('search');

        if (! $search) {
            $this->error('Please provide a search term using --search option');
            $this->info('Example: php artisan deployer:logs:search production --search="ERROR"');

            return self::FAILURE;
        }

        $validEnvironments = ['local', 'staging', 'production'];
        if (! in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: '.implode(', ', $validEnvironments));

            return self::FAILURE;
        }

        $command = [
            'vendor/bin/dep',
            'logs:search',
            $environment,
            "--search={$search}",
        ];

        if ($lines = $this->option('lines')) {
            $command[] = "--lines={$lines}";
        }

        if ($logfile = $this->option('logfile')) {
            $command[] = "--logfile={$logfile}";
        }

        $this->info("Searching logs on {$environment} for: {$search}");
        $this->newLine();

        $process = new Process($command, base_path(), null, null, null);
        $process->setTty(Process::isTtySupported());

        try {
            $process->run(function ($type, $buffer) {
                echo $buffer;
            });

            if ($process->isSuccessful()) {
                return self::SUCCESS;
            } else {
                $this->newLine();
                $this->error('Log search failed!');

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
