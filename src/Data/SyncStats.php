<?php

namespace Shaf\LaravelDeployer\Data;

use Illuminate\Support\Number;
use Shaf\LaravelDeployer\Services\RsyncService;

/**
 * Actual sync statistics captured after file transfer.
 * Unlike SyncDiff which represents theoretical changes, this captures real transfer data.
 */
readonly class SyncStats
{
    public function __construct(
        public int $filesSynced = 0,
        public int $filesAdded = 0,
        public int $filesModified = 0,
        public int $filesDeleted = 0,
        public int $bytesTransferred = 0,
    ) {}

    /**
     * Create stats from RsyncService after sync completes
     */
    public static function fromRsync(RsyncService $rsync, ?SyncDiff $diff = null): self
    {
        return new self(
            filesSynced: $rsync->getFilesSynced(),
            filesAdded: $diff?->newCount() ?? 0,
            filesModified: $diff?->modifiedCount() ?? 0,
            filesDeleted: $diff?->deletedCount() ?? 0,
            bytesTransferred: $rsync->getTotalBytesTransferred(),
        );
    }

    /**
     * Create empty stats (for first deployment or errors)
     */
    public static function empty(): self
    {
        return new self;
    }

    public function hasChanges(): bool
    {
        return $this->filesSynced > 0 || $this->bytesTransferred > 0;
    }

    public function getFormattedSize(): string
    {
        return Number::fileSize($this->bytesTransferred);
    }

    public function toArray(): array
    {
        return [
            'files_synced' => $this->filesSynced,
            'files_added' => $this->filesAdded,
            'files_modified' => $this->filesModified,
            'files_deleted' => $this->filesDeleted,
            'bytes_transferred' => $this->bytesTransferred,
        ];
    }
}
