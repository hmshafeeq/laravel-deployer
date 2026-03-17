<?php

namespace Shaf\LaravelDeployer\Data;

readonly class SshResult
{
    public function __construct(
        public bool $successful,
        public int $exitCode,
        public string $output,
        public string $errorOutput,
    ) {}
}
