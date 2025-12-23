<?php

namespace Shaf\LaravelDeployer\ValueObjects;

class BackupInfo
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly string $size
    ) {}

    public function getBasename(): string
    {
        return basename($this->path);
    }
}
