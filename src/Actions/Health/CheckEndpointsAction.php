<?php

namespace Shaf\LaravelDeployer\Actions\Health;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;

class CheckEndpointsAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config,
        protected array $endpoints = []
    ) {
    }

    public function execute(): void
    {
        if (empty($this->endpoints)) {
            // Default endpoint check
            $this->endpoints = ['http://localhost'];
        }

        $this->output->info("Checking application endpoints...");

        foreach ($this->endpoints as $endpoint) {
            $this->checkEndpoint($endpoint);
        }

        $this->output->success("All endpoints are healthy");
    }

    protected function checkEndpoint(string $endpoint): void
    {
        $this->output->info("Checking: {$endpoint}");

        try {
            // Use curl to check the endpoint
            $response = $this->executor->execute(
                "curl -s -o /dev/null -w '%{http_code}' --max-time 10 {$endpoint}"
            );

            $statusCode = (int) trim($response);

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->output->success("✓ {$endpoint} returned {$statusCode}");
            } elseif ($statusCode >= 300 && $statusCode < 400) {
                $this->output->warn("⚠ {$endpoint} returned redirect {$statusCode}");
            } else {
                throw new \RuntimeException("{$endpoint} returned error code: {$statusCode}");
            }
        } catch (\Exception $e) {
            $this->output->error("✗ {$endpoint} check failed: " . $e->getMessage());
            throw $e;
        }
    }
}
