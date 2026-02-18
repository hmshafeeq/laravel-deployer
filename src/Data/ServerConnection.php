<?php

namespace Shaf\LaravelDeployer\Data;

readonly class ServerConnection
{
    public function __construct(
        public string $host,
        public string $user,
        public ?int $port = null,
        public bool $disableStrictHostKeyChecking = true,
        public bool $disablePasswordAuth = true,
        public ?string $identityFile = null,
    ) {}

    public static function fromConfig(DeploymentConfig $config): self
    {
        return new self(
            host: $config->hostname,
            user: $config->remoteUser,
            port: $config->port,
        );
    }

    public function getConnectionString(): string
    {
        $parts = ["{$this->user}@{$this->host}"];

        if ($this->port !== null) {
            $parts[] = "-p {$this->port}";
        }

        if ($this->identityFile !== null) {
            $parts[] = "-i {$this->identityFile}";
        }

        return implode(' ', $parts);
    }
}
