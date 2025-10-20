<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ClearCommand extends Command
{
    protected $signature = 'deployer:clear {environment=staging : The deployment environment (local, staging, production)}
                            {--no-confirm : Skip confirmation prompt}';

    protected $description = 'Clear all caches and restart services using Deployer';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $noConfirm = $this->option('no-confirm');

        $validEnvironments = ['local', 'staging', 'production'];
        if (! in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: '.implode(', ', $validEnvironments));

            return self::FAILURE;
        }

        // Show confirmation for non-local environments
        if ($environment !== 'local' && ! $noConfirm) {
            $this->warn("⚠️  You are about to clear caches and restart services on {$environment}");

            if (! $this->confirm('Do you want to continue?', false)) {
                $this->info('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        // Build the deployer command
        $command = [
            'vendor/bin/dep',
            'system:clear',
            $environment,
        ];

        $this->info("Clearing caches and restarting services on {$environment}...");
        $this->newLine();

        // Execute the deployer command
        $process = new Process($command, base_path(), null, null, null);
        $process->setTty(Process::isTtySupported());

        try {
            $process->run(function ($type, $buffer) {
                echo $buffer;
            });

            if ($process->isSuccessful()) {
                $this->newLine();
                $this->info('✅ System clear completed successfully!');

                return self::SUCCESS;
            } else {
                $this->newLine();
                $this->error('❌ System clear failed!');

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
