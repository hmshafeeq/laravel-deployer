<?php

namespace Shaf\LaravelDeployer\Data;

use DateTimeImmutable;
use Shaf\LaravelDeployer\Services\SshService;

readonly class DeploymentReceipt
{
    public function __construct(
        public string $release,
        public string $environment,
        public DateTimeImmutable $deployedAt,
        public string $deployedBy,
        public float $durationSeconds,
        public ?string $gitCommit = null,
        public ?string $gitBranch = null,
        public ?string $gitMessage = null,
        public int $filesSynced = 0,
        public int $filesAdded = 0,
        public int $filesModified = 0,
        public int $filesDeleted = 0,
        public int $bytesTransferred = 0,
        public array $migrationsRun = [],
        public array $postDeployCommands = [],
        public bool $success = true,
        public ?string $errorMessage = null,
    ) {}

    public function toArray(): array
    {
        return [
            'release' => $this->release,
            'environment' => $this->environment,
            'deployed_at' => $this->deployedAt->format('c'),
            'deployed_by' => $this->deployedBy,
            'duration_seconds' => round($this->durationSeconds, 2),
            'git' => [
                'commit' => $this->gitCommit,
                'branch' => $this->gitBranch,
                'message' => $this->gitMessage,
            ],
            'stats' => [
                'files_synced' => $this->filesSynced,
                'files_added' => $this->filesAdded,
                'files_modified' => $this->filesModified,
                'files_deleted' => $this->filesDeleted,
                'bytes_transferred' => $this->bytesTransferred,
            ],
            'migrations' => $this->migrationsRun,
            'post_deploy_commands' => $this->postDeployCommands,
            'success' => $this->success,
            'error' => $this->errorMessage,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Create a receipt from deployment context
     */
    public static function fromDeployment(
        string $release,
        string $environment,
        string $deployedBy,
        float $duration,
        ?SyncStats $syncStats = null,
        array $postDeployCommands = [],
        bool $success = true,
        ?string $errorMessage = null
    ): self {
        // Get git info
        $suppress = SshService::suppressStderr();
        $gitCommit = trim((string) shell_exec("git rev-parse HEAD {$suppress}"));
        $gitBranch = trim((string) shell_exec("git rev-parse --abbrev-ref HEAD {$suppress}"));
        $gitMessage = trim((string) shell_exec("git log -1 --pretty=%s {$suppress}"));

        return new self(
            release: $release,
            environment: $environment,
            deployedAt: new DateTimeImmutable,
            deployedBy: $deployedBy,
            durationSeconds: $duration,
            gitCommit: $gitCommit ?: null,
            gitBranch: $gitBranch ?: null,
            gitMessage: $gitMessage ?: null,
            filesSynced: $syncStats !== null ? $syncStats->filesSynced : 0,
            filesAdded: $syncStats !== null ? $syncStats->filesAdded : 0,
            filesModified: $syncStats !== null ? $syncStats->filesModified : 0,
            filesDeleted: $syncStats !== null ? $syncStats->filesDeleted : 0,
            bytesTransferred: $syncStats !== null ? $syncStats->bytesTransferred : 0,
            postDeployCommands: $postDeployCommands,
            success: $success,
            errorMessage: $errorMessage,
        );
    }
}
