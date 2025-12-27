<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\CommandService;

/**
 * Base action class providing shared utilities for deployment actions.
 */
abstract class Action
{
    public function __construct(
        protected CommandService $cmd,
        protected DeploymentConfig $config
    ) {}

    /**
     * Write content to a file with secure permissions (600).
     * Useful for credentials files like MySQL config, auth.json, etc.
     *
     * @return string The path to the created file
     */
    protected function writeSecureFile(string $path, string $content, string $permissions = '600'): string
    {
        $escapedContent = escapeshellarg($content);
        $escapedPath = CommandService::escapePath($path);

        $this->cmd->remote("echo {$escapedContent} > {$escapedPath} && chmod {$permissions} {$escapedPath}");

        return $path;
    }

    /**
     * Append a log entry to the deployment log file.
     */
    protected function logToDeployLog(string $message): void
    {
        $logFile = "{$this->config->deployPath}/.dep/deploy.log";
        $escapedLogFile = CommandService::escapePath($logFile);
        $escapedMessage = escapeshellarg($message);

        $this->cmd->remote("echo {$escapedMessage} >> {$escapedLogFile}");
    }

    /**
     * Create a timestamped log entry with user info.
     */
    protected function formatLogEntry(string $action): string
    {
        $timestamp = date('Y-m-d H:i:s');

        return "[{$timestamp}] {$action} on {$this->config->environment->value}";
    }
}
