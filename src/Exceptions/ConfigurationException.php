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

    public static function circularInheritance(string $chain): self
    {
        return new self("Circular environment inheritance detected: {$chain}");
    }

    public static function parentEnvironmentNotFound(string $parent, string $child): self
    {
        return new self(
            "Environment '{$child}' extends '{$parent}', but '{$parent}' does not exist in deploy.json"
        );
    }
}
