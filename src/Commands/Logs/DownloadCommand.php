<?php

namespace Shaf\LaravelDeployer\Commands\Logs;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DownloadCommand extends Command
{
    protected $signature = 'deployer:logs:download
                            {environment=staging : The deployment environment (local, staging, production)}
                            {--destination= : Destination path (defaults to .deploy/downloads/logs/{timestamp}/)}
                            {--logfile= : Specific log file to download}';

    protected $description = 'Download application logs from remote server';

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
            'logs:download',
            $environment,
        ];

        // Set default destination with timestamp or use provided one
        if ($destination = $this->option('destination')) {
            $command[] = "--destination={$destination}";
        } else {
            $timestamp = time();
            $defaultDestination = ".deploy/downloads/logs/{$timestamp}/";
            $command[] = "--destination={$defaultDestination}";
            $this->info("Downloading to: {$defaultDestination}");
        }

        if ($logfile = $this->option('logfile')) {
            $command[] = "--logfile={$logfile}";
        }

        $this->info("Downloading logs from {$environment}...");
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
                $this->error('Log download failed!');

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
