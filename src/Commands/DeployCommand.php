<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DeployCommand extends Command
{
    protected $signature = 'deploy {environment=staging : The deployment environment (local, staging, production)}
                            {task=deploy : The deployment task to run (deploy, deploy:full, rollback:quick, rollback:full, etc.)}
                            {--no-confirm : Skip deployment confirmation}';

    protected $description = 'Deploy the application using Deployer';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $task = $this->argument('task');
        $noConfirm = $this->option('no-confirm');

        $validEnvironments = ['local', 'staging', 'production'];
        if (! in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: '.implode(', ', $validEnvironments));

            return self::FAILURE;
        }

        // Build the deployer command
        $command = [
            'vendor/bin/dep',
            $task,
            $environment,
        ];

        // Add options
        if ($noConfirm) {
            $command[] = '--no-confirm';
        }

        $this->info("Starting deployment: {$task} to {$environment}");
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
                $this->info('Deployment completed successfully!');

                return self::SUCCESS;
            } else {
                $this->newLine();
                $this->error('Deployment failed!');

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Deployment error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
