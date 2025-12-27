<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\DeploymentReceipt;

/**
 * Service for generating and storing deployment receipts.
 * Receipts provide an audit trail of all deployments.
 */
class ReceiptService
{
    public function __construct(
        private CommandService $cmd,
        private DeploymentConfig $config
    ) {}

    /**
     * Generate and store a deployment receipt on the server
     */
    public function save(DeploymentReceipt $receipt): void
    {
        $receiptsDir = "{$this->config->deployPath}/.dep/receipts";
        $receiptPath = "{$receiptsDir}/{$receipt->release}.json";

        $escapedDir = CommandService::escapePath($receiptsDir);
        $escapedPath = CommandService::escapePath($receiptPath);
        $escapedContent = escapeshellarg($receipt->toJson());

        // Create receipts directory if it doesn't exist and write the receipt
        $this->cmd->remote("mkdir -p {$escapedDir} && echo {$escapedContent} > {$escapedPath}");

        $this->cmd->debug("Receipt saved to {$receiptPath}");
    }

    /**
     * Get a receipt by release name
     */
    public function get(string $releaseName): ?DeploymentReceipt
    {
        $receiptPath = "{$this->config->deployPath}/.dep/receipts/{$releaseName}.json";
        $escapedPath = CommandService::escapePath($receiptPath);

        try {
            $content = $this->cmd->remote("cat {$escapedPath} 2>/dev/null || echo ''");

            if (empty(trim($content))) {
                return null;
            }

            $data = json_decode($content, true);

            if (! $data) {
                return null;
            }

            return new DeploymentReceipt(
                release: $data['release'] ?? $releaseName,
                environment: $data['environment'] ?? '',
                deployedAt: new \DateTimeImmutable($data['deployed_at'] ?? 'now'),
                deployedBy: $data['deployed_by'] ?? '',
                durationSeconds: $data['duration_seconds'] ?? 0,
                gitCommit: $data['git']['commit'] ?? null,
                gitBranch: $data['git']['branch'] ?? null,
                gitMessage: $data['git']['message'] ?? null,
                filesSynced: $data['stats']['files_synced'] ?? 0,
                filesAdded: $data['stats']['files_added'] ?? 0,
                filesModified: $data['stats']['files_modified'] ?? 0,
                filesDeleted: $data['stats']['files_deleted'] ?? 0,
                bytesTransferred: $data['stats']['bytes_transferred'] ?? 0,
                migrationsRun: $data['migrations'] ?? [],
                postDeployCommands: $data['post_deploy_commands'] ?? [],
                success: $data['success'] ?? true,
                errorMessage: $data['error'] ?? null,
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * List all available receipts
     */
    public function list(): array
    {
        $receiptsDir = "{$this->config->deployPath}/.dep/receipts";
        $escapedDir = CommandService::escapePath($receiptsDir);

        try {
            $output = $this->cmd->remote("ls -1 {$escapedDir} 2>/dev/null | grep '\\.json$' | sed 's/\\.json$//' | sort -r || echo ''");

            if (empty(trim($output))) {
                return [];
            }

            return array_filter(explode("\n", trim($output)));
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get the most recent receipt
     */
    public function latest(): ?DeploymentReceipt
    {
        $releases = $this->list();

        if (empty($releases)) {
            return null;
        }

        return $this->get($releases[0]);
    }
}
