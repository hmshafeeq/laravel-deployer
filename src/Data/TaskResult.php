<?php

namespace Shaf\LaravelDeployer\Data;

use Shaf\LaravelDeployer\Enums\TaskStatus;

readonly class TaskResult
{
    public function __construct(
        public string $name,
        public TaskStatus $status,
        public string $output = '',
        public ?string $error = null,
        public float $duration = 0.0,
    ) {}

    public static function success(string $name, string $output = '', float $duration = 0.0): self
    {
        return new self(
            name: $name,
            status: TaskStatus::COMPLETED,
            output: $output,
            duration: $duration,
        );
    }

    public static function failure(string $name, string $error, float $duration = 0.0): self
    {
        return new self(
            name: $name,
            status: TaskStatus::FAILED,
            error: $error,
            duration: $duration,
        );
    }

    public static function skipped(string $name): self
    {
        return new self(
            name: $name,
            status: TaskStatus::SKIPPED,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    public function isFailed(): bool
    {
        return $this->status === TaskStatus::FAILED;
    }
}
