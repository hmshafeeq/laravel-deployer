<?php

namespace Shaf\LaravelDeployer\Exceptions;

use Exception;

class RsyncException extends Exception
{
    public static function failed(string $error): self
    {
        return new self("Rsync failed: {$error}");
    }
}
