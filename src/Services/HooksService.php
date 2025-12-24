<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Data\DeploymentConfig;

/**
 * Service for managing deployment hooks.
 * Hooks allow running custom commands at specific points in the deployment process.
 *
 * Supported hook points:
 * - before:deploy - Before deployment starts
 * - after:setup - After deployment structure is created
 * - before:build - Before assets are built
 * - after:build - After assets are built
 * - before:sync - Before files are synced
 * - after:sync - After files are synced
 * - before:composer - Before composer install
 * - after:composer - After composer install
 * - before:migrate - Before migrations run
 * - after:migrate - After migrations run
 * - before:symlink - Before release is symlinked
 * - after:symlink - After release is symlinked
 * - after:deploy - After deployment completes
 * - on:failure - When deployment fails
 */
class HooksService
{
    private array $hooks = [];

    private string $currentReleasePath = '';

    public function __construct(
        private CommandService $cmd,
        private DeploymentConfig $config
    ) {}

    /**
     * Load hooks from configuration
     */
    public function loadHooks(array $hooks): self
    {
        $this->hooks = $hooks;

        return $this;
    }

    /**
     * Set the current release path for command execution
     */
    public function setReleasePath(string $path): self
    {
        $this->currentReleasePath = $path;

        return $this;
    }

    /**
     * Run hooks for a specific point
     */
    public function run(string $hookPoint): void
    {
        $commands = $this->hooks[$hookPoint] ?? [];

        if (empty($commands)) {
            return;
        }

        $this->cmd->task("hooks:{$hookPoint}");
        $this->cmd->info("Running {$hookPoint} hooks...");

        foreach ($commands as $command) {
            $this->executeHookCommand($command, $hookPoint);
        }

        $this->cmd->success("{$hookPoint} hooks completed");
    }

    /**
     * Check if a hook point has any commands
     */
    public function hasHooks(string $hookPoint): bool
    {
        return ! empty($this->hooks[$hookPoint] ?? []);
    }

    /**
     * Get all hook points that have commands
     */
    public function getActiveHookPoints(): array
    {
        return array_keys(array_filter($this->hooks, fn ($cmds) => ! empty($cmds)));
    }

    /**
     * Execute a single hook command
     */
    private function executeHookCommand(string $command, string $hookPoint): void
    {
        $this->cmd->info("  → {$command}");

        try {
            // Check if it's an artisan command
            if (str_starts_with($command, 'artisan ')) {
                $artisanCommand = substr($command, 8);
                $this->cmd->artisan($artisanCommand, $this->currentReleasePath);

                return;
            }

            // Check if it's a notification command
            if (str_starts_with($command, 'notify:')) {
                $this->handleNotification($command);

                return;
            }

            // Check if it's a local command
            if (str_starts_with($command, 'local:')) {
                $localCommand = substr($command, 6);
                $this->cmd->local($localCommand);

                return;
            }

            // Default: execute as remote shell command
            $escapedPath = CommandService::escapePath($this->currentReleasePath);
            $this->cmd->remote("cd {$escapedPath} && {$command}");

        } catch (\Exception $e) {
            // Log the error but don't fail the deployment for non-critical hooks
            if ($this->isCriticalHook($hookPoint)) {
                throw $e;
            }

            $this->cmd->warning("  ⚠ Hook failed: {$e->getMessage()}");
        }
    }

    /**
     * Handle notification commands
     */
    private function handleNotification(string $command): void
    {
        $parts = explode(':', $command, 2);
        $channel = $parts[1] ?? 'default';

        $this->cmd->info("  Sending notification to {$channel}");

        // The actual notification is handled by NotificationAction
        // This is just a placeholder for hook-triggered notifications
    }

    /**
     * Check if a hook point is critical (should fail deployment on error)
     */
    private function isCriticalHook(string $hookPoint): bool
    {
        // before:* hooks and migrate hooks are critical
        return str_starts_with($hookPoint, 'before:') ||
               in_array($hookPoint, ['after:migrate', 'before:symlink']);
    }

    /**
     * Get list of valid hook points
     */
    public static function getValidHookPoints(): array
    {
        return [
            'before:deploy',
            'after:setup',
            'before:build',
            'after:build',
            'before:sync',
            'after:sync',
            'before:composer',
            'after:composer',
            'before:migrate',
            'after:migrate',
            'before:symlink',
            'after:symlink',
            'after:deploy',
            'on:failure',
        ];
    }
}
