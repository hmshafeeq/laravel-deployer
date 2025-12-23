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
        return $this->port
            ? "{$this->user}@{$this->host}:{$this->port}"
            : "{$this->user}@{$this->host}";
    }
}
