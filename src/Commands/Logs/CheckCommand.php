<?php

namespace Shaf\LaravelDeployer\Commands\Logs;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class CheckCommand extends Command
{
    protected $signature = 'deployer:logs {environment=staging : The deployment environment (local, staging, production)}';

    protected $description = 'Check application logs for errors and warnings (last 7 days)';

    public function handle(): int
    {
        $environment = $this->argument('environment');

        $validEnvironments = ['local', 'staging', 'production'];
        if (! in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: '.implode(', ', $validEnvironments));

            return self::FAILURE;
        }

        $command = [
            'vendor/bin/dep',
            'logs:check',
            $environment,
        ];

        $this->info("Checking logs on {$environment}...");
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
                $this->error('Log check failed!');

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
