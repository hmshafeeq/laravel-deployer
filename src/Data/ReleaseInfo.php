<?php

namespace Shaf\LaravelDeployer\Data;

use DateTimeImmutable;

readonly class ReleaseInfo
{
    private const PATTERN = '/^\d{6}\.\d+$/'; // Format: YYYYMM.N (e.g., 202501.1)

    public function __construct(
        public string $name,
        public DateTimeImmutable $createdAt,
        public string $user,
        public string $branch,
    ) {
        $this->validateName($name);
    }

    public static function create(string $name, string $user, string $branch): self
    {
        return new self(
            name: $name,
            createdAt: new DateTimeImmutable(),
            user: $user,
            branch: $branch,
        );
    }

    public static function fromLogEntry(array $entry): self
    {
        return new self(
            name: $entry['release_name'],
            createdAt: new DateTimeImmutable($entry['created_at']),
            user: $entry['user'],
            branch: $entry['target'] ?? 'unknown',
        );
    }

    public function toLogEntry(): array
    {
        return [
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:sP'),
            'release_name' => $this->name,
            'user' => $this->user,
            'target' => $this->branch,
        ];
    }

    private function validateName(string $name): void
    {
        if (!preg_match(self::PATTERN, $name)) {
            throw new \InvalidArgumentException(
                "Invalid release name format: {$name}. Expected format: YYYYMM.N (e.g., 202501.1)"
            );
        }
    }

    public function getYearMonth(): string
    {
        return explode('.', $this->name)[0];
    }

    public function getSequenceNumber(): int
    {
        return (int) explode('.', $this->name)[1];
    }
}
