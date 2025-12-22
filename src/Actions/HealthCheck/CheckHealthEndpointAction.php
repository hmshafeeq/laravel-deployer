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

                $this->writeln("run timeout {$timeout} curl -s --max-time 10 --connect-timeout {$connectTimeout} {$healthUrl}");
                $response = $this->cmd("timeout {$timeout} curl -s --max-time 10 --connect-timeout {$connectTimeout} {$healthUrl}");
                $this->writeln($response);

                $this->writeln("run timeout {$timeout} curl -s --max-time 10 --connect-timeout {$connectTimeout} -o /dev/null -w '%{http_code}' {$healthUrl}");
                $statusCode = $this->cmd("timeout {$timeout} curl -s --max-time 10 --connect-timeout {$connectTimeout} -o /dev/null -w '%{http_code}' {$healthUrl}");
                $this->writeln($statusCode);

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
        $this->writeln("run echo '{$healthResponse}' | python3 -m json.tool 2>/dev/null || echo '{$healthResponse}'");
        $prettyHealth = $this->cmd("echo '{$healthResponse}' | python3 -m json.tool 2>/dev/null || echo '{$healthResponse}'");

        $lines = explode("\n", trim($prettyHealth));
        foreach ($lines as $line) {
            $this->writeln($line);
        }
        echo $prettyHealth."\n";

        $this->writeln('');
    }
}
