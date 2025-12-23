<?php

namespace Shaf\LaravelDeployer\Exceptions;

use Exception;

class HealthCheckException extends Exception
{
    public static function endpointFailed(string $url, int $statusCode, string $response): self
    {
        return new self(
            "Health endpoint failed: {$url}\nHTTP {$statusCode}\nResponse: {$response}"
        );
    }

    public static function diskSpaceCritical(int $usedPercent, string $available): self
    {
        return new self(
            "Disk space critical! {$usedPercent}% used, {$available} available. Please free up space before deployment."
        );
    }

    public static function smokTestFailed(string $endpoint, string $description, string $response): self
    {
        return new self(
            "Smoke test failed for {$endpoint} ({$description}). HTTP: {$response}"
        );
    }

    public static function timeout(string $url): self
    {
        return new self("Health check timed out: {$url}");
    }
}
