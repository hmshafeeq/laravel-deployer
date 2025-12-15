<?php

namespace Shaf\LaravelDeployer\Exceptions;

use Exception;

class SSHConnectionException extends Exception
{
    public static function connectionFailed(string $host, string $user, string $reason): self
    {
        return new self("Failed to connect to {$user}@{$host}: {$reason}");
    }

    public static function commandFailed(string $command, string $error): self
    {
        return new self("Remote command failed: {$command}\n{$error}");
    }

    public static function timeout(string $command, int $timeout): self
    {
        return new self("Command timed out after {$timeout} seconds: {$command}");
    }
}
