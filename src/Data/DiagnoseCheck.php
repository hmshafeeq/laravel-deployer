<?php

namespace Shaf\LaravelDeployer\Data;

/**
 * Represents a single diagnostic check result.
 */
class DiagnoseCheck
{
    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    public const STATUS_WARN = 'warn';

    public const STATUS_SKIP = 'skip';

    public function __construct(
        public string $name,
        public string $status,
        public string $message,
        public ?string $actual = null,
        public ?string $expected = null,
        public ?string $fix = null,
    ) {}

    public function passed(): bool
    {
        return $this->status === self::STATUS_PASS;
    }

    public function failed(): bool
    {
        return $this->status === self::STATUS_FAIL;
    }

    public function warned(): bool
    {
        return $this->status === self::STATUS_WARN;
    }

    public function skipped(): bool
    {
        return $this->status === self::STATUS_SKIP;
    }

    public function getIcon(): string
    {
        return match ($this->status) {
            self::STATUS_PASS => '✓',
            self::STATUS_FAIL => '✗',
            self::STATUS_WARN => '⚠',
            self::STATUS_SKIP => '○',
            default => '?',
        };
    }

    public static function pass(string $name, string $message, ?string $actual = null): self
    {
        return new self($name, self::STATUS_PASS, $message, $actual);
    }

    public static function fail(string $name, string $message, ?string $actual = null, ?string $expected = null, ?string $fix = null): self
    {
        return new self($name, self::STATUS_FAIL, $message, $actual, $expected, $fix);
    }

    public static function warn(string $name, string $message, ?string $actual = null, ?string $expected = null): self
    {
        return new self($name, self::STATUS_WARN, $message, $actual, $expected);
    }

    public static function skip(string $name, string $message): self
    {
        return new self($name, self::STATUS_SKIP, $message);
    }
}
