<?php

namespace Shaf\LaravelDeployer\Exceptions;

use Exception;

class DeploymentException extends Exception
{
    public static function locked(string $lockFile): self
    {
        return new self("Deployment is locked. Lock file exists: {$lockFile}");
    }

    public static function taskFailed(string $taskName, string $reason): self
    {
        return new self("Task '{$taskName}' failed: {$reason}");
    }

    public static function releaseNotFound(string $release): self
    {
        return new self("Release '{$release}' does not exist");
    }

    public static function noPreviousRelease(): self
    {
        return new self('No previous release available for rollback');
    }

    public static function noReleases(): self
    {
        return new self('No releases found');
    }
}
