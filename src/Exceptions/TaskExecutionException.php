<?php

namespace Shaf\LaravelDeployer\Exceptions;

use Exception;

class TaskExecutionException extends Exception
{
    public static function commandFailed(string $command, string $error): self
    {
        return new self("Command failed: {$command}\n{$error}");
    }

    public static function artisanFailed(string $command, string $error): self
    {
        return new self("Artisan command failed: {$command}\n{$error}");
    }

    public static function serviceFailed(string $service, string $error): self
    {
        return new self("Service operation failed: {$service}\n{$error}");
    }
}
