<?php

namespace Shaf\LaravelDeployer\Exceptions;

use Exception;

class ConfigurationException extends Exception
{
    public static function fileNotFound(string $path): self
    {
        return new self("Configuration file not found: {$path}");
    }

    public static function environmentNotFound(string $environment, array $available): self
    {
        $availableList = implode(', ', $available);

        return new self(
            "Environment '{$environment}' not found in deploy.json. Available: {$availableList}"
        );
    }

    public static function invalidJson(string $path, string $reason): self
    {
        return new self("Failed to parse JSON configuration {$path}: {$reason}");
    }

    public static function missingRequiredKey(string $key): self
    {
        return new self("Required configuration key missing: {$key}");
    }
}
