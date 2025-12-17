<?php

namespace Shaf\LaravelDeployer\Enums;

enum TaskStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::SKIPPED]);
    }

    public function isSuccessful(): bool
    {
        return $this === self::COMPLETED;
    }
}
