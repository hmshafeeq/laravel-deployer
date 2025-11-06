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

        $validEnvironments = ['local', 'staging', 'production'];
        if (! in_array($environment, $validEnvironments)) {
            $this->error("Invalid environment: {$environment}");
            $this->info('Valid environments: '.implode(', ', $validEnvironments));

            return self::FAILURE;
        }

        // Check if Vite is running
        if ($this->isViteRunning()) {
            $this->newLine();
            $this->components->error('Vite bundler is currently running!');
            $this->newLine();
            $this->components->warn('Please stop the Vite development server before deploying. 💡 Press Ctrl+C in the terminal where Vite is running to stop it.');
            $this->newLine();

            return self::FAILURE;
        }

        // Build the deployer command
        $command = [
            'vendor/bin/dep',
            $task,
            $environment,
        ];



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

    protected function isViteRunning(): bool
    {
        $process = new Process(['ps', 'aux']);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        $output = $process->getOutput();
        $projectPath = base_path();

        // Look for vite processes running from this project's directory
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, 'node_modules/.bin/vite') && str_contains($line, $projectPath)) {
                return true;
            }
        }

        return false;
    }
}
