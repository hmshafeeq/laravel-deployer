<?php

namespace Shaf\LaravelDeployer\Data;

readonly class SyncDiff
{
    public function __construct(
        public array $newFiles = [],
        public array $modifiedFiles = [],
        public array $deletedFiles = []
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->newFiles) && empty($this->modifiedFiles) && empty($this->deletedFiles);
    }

    public function hasNew(): bool
    {
        return ! empty($this->newFiles);
    }

    public function hasModified(): bool
    {
        return ! empty($this->modifiedFiles);
    }

    public function hasDeleted(): bool
    {
        return ! empty($this->deletedFiles);
    }

    public function newCount(): int
    {
        return count($this->newFiles);
    }

    public function modifiedCount(): int
    {
        return count($this->modifiedFiles);
    }

    public function deletedCount(): int
    {
        return count($this->deletedFiles);
    }

    public function totalCount(): int
    {
        return $this->newCount() + $this->modifiedCount() + $this->deletedCount();
    }

    /**
     * Get all files across all categories (new, modified, deleted)
     *
     * @return array<string>
     */
    public function allFiles(): array
    {
        return array_merge($this->newFiles, $this->modifiedFiles, $this->deletedFiles);
    }
}
