<?php

namespace Shaf\LaravelDeployer\Enums;

enum Environment: string
{
    case LOCAL = 'local';
    case STAGING = 'staging';
    case PRODUCTION = 'production';

    public function isProduction(): bool
    {
        return $this === self::PRODUCTION;
    }

    public function isLocal(): bool
    {
        return $this === self::LOCAL;
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        // Handle common aliases
        return match ($normalized) {
            'prod' => self::PRODUCTION,
            'stage', 'stg' => self::STAGING,
            'dev', 'development' => self::LOCAL,
            default => self::tryFrom($normalized)
                ?? throw new \InvalidArgumentException(
                    "Invalid environment: {$value}. Valid: local, staging, production"
                ),
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::LOCAL => 'Local',
            self::STAGING => 'Staging',
            self::PRODUCTION => 'Production',
        };
    }
}
