<?php

namespace Shaf\LaravelDeployer\Actions\HealthCheck;

use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Services\CommandRetryService;
use Shaf\LaravelDeployer\Support\Abstract\HealthCheckAction;

class CheckHealthEndpointAction extends HealthCheckAction
{
    public function __construct(
        protected Deployer $deployer,
        protected ?CommandRetryService $retry = null
    ) {
        parent::__construct($deployer);
        $this->retry = $retry ?? new CommandRetryService;
    }

    public function execute(string $appUrl): string
    {
        $healthUrl = rtrim($appUrl, '/').'/health';
        $maxRetries = config('laravel-deployer.health_check.max_retries', 3);
        $retryDelay = config('laravel-deployer.health_check.retry_delay', 5);
        $timeout = config('laravel-deployer.health_check.timeout', 30);
        $connectTimeout = config('laravel-deployer.health_check.connect_timeout', 5);

        $healthResponse = $this->retry->retry(
            callback: function ($attempt) use ($healthUrl, $timeout, $connectTimeout, $maxRetries) {
                $this->writeln("🔄 Health check attempt {$attempt}/{$maxRetries}...");

                // Single curl request that returns body + status code (status on last line)
                $command = "curl -s --max-time {$timeout} --connect-timeout {$connectTimeout} -w '\\n%{http_code}' {$healthUrl}";
                $this->writeln("run {$command}");

                $output = $this->cmd($command);
                $lines = explode("\n", trim($output));
                $statusCode = array_pop($lines);
                $response = implode("\n", $lines);

                $this->writeln("HTTP {$statusCode}");

                if ($statusCode !== '200') {
                    throw new \RuntimeException("Health check failed with HTTP {$statusCode}");
                }

                return $response;
            },
            maxRetries: $maxRetries,
            delaySeconds: $retryDelay,
            onRetry: function ($attempt) {
                $this->writeln('⚠️  Health check failed, retrying in 5 seconds...', 'comment');
            }
        );

        $this->displayHealthStatus($healthResponse);

        return $healthResponse;
    }

    /**
     * Display health check status with pretty formatting
     */
    protected function displayHealthStatus(string $healthResponse): void
    {
        $this->writeln('📊 Health Status:');

        // Format JSON locally instead of remote python call
        $decoded = json_decode($healthResponse, true);
        if ($decoded !== null) {
            $prettyHealth = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            $prettyHealth = $healthResponse;
        }

        $lines = explode("\n", trim($prettyHealth));
        foreach ($lines as $line) {
            $this->writeln($line);
        }

        $this->writeln('');
    }
}
