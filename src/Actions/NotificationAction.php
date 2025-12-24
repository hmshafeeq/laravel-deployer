<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Contracts\NotificationChannel;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Notifications\DiscordChannel;
use Shaf\LaravelDeployer\Notifications\SlackChannel;

/**
 * Notification action.
 * Handles sending deployment notifications to various channels.
 */
class NotificationAction
{
    /** @var NotificationChannel[] */
    private array $channels = [];

    public function __construct(
        private DeploymentConfig $config
    ) {
        $this->registerDefaultChannels();
    }

    /**
     * Add a custom notification channel.
     */
    public function addChannel(NotificationChannel $channel): self
    {
        $this->channels[] = $channel;

        return $this;
    }

    /**
     * Send success notification with rich details
     *
     * @param  array{
     *     environment?: string,
     *     release?: string,
     *     duration?: float,
     *     gitInfo?: array{branch: string, commit: ?string, message: ?string, author: ?string},
     *     url?: string,
     *     filesChanged?: int
     * }  $data
     */
    public function success(array $data = []): void
    {
        $environment = $data['environment'] ?? $this->config->environment->value;
        $release = $data['release'] ?? 'unknown';
        $duration = $data['duration'] ?? null;
        $gitInfo = $data['gitInfo'] ?? null;
        $url = $data['url'] ?? null;
        $filesChanged = $data['filesChanged'] ?? null;

        $lines = ["✅ Deployment to {$environment} successful!"];

        // Add release info with git details
        if ($gitInfo !== null && ! empty($gitInfo['commit'])) {
            $branch = $gitInfo['branch'] ?? 'unknown';
            $commit = $gitInfo['commit'];
            $lines[] = "Release: {$release} ({$branch} @ {$commit})";
        } else {
            $lines[] = "Release: {$release}";
        }

        // Add duration
        if ($duration !== null) {
            $lines[] = "Duration: {$this->formatDuration($duration)}";
        }

        // Add files changed
        if ($filesChanged !== null && $filesChanged > 0) {
            $lines[] = "Files: {$filesChanged} changed";
        }

        // Add URL
        if ($url !== null) {
            $lines[] = "🌐 {$url}";
        }

        $message = implode("\n", $lines);
        $this->sendToAllChannels($message, 'success');
    }

    /**
     * Send failure notification with details
     */
    public function failure(\Exception $exception, array $data = []): void
    {
        $environment = $data['environment'] ?? $this->config->environment->value;
        $release = $data['release'] ?? null;
        $failedStep = $data['failedStep'] ?? null;

        $lines = ["❌ Deployment to {$environment} failed!"];

        if ($release !== null) {
            $lines[] = "Release: {$release}";
        }

        if ($failedStep !== null) {
            $lines[] = "Failed at: {$failedStep}";
        }

        // Truncate long error messages
        $errorMessage = $exception->getMessage();
        if (strlen($errorMessage) > 200) {
            $errorMessage = substr($errorMessage, 0, 197).'...';
        }

        $lines[] = "Error: {$errorMessage}";
        $lines[] = 'Time: '.date('Y-m-d H:i:s');

        $message = implode("\n", $lines);
        $this->sendToAllChannels($message, 'failure');
    }

    /**
     * Send rollback notification
     */
    public function rollback(string $fromRelease, string $toRelease): void
    {
        $environment = $this->config->environment->value;

        $message = "🔄 Rollback on {$environment}\n".
                   "From: {$fromRelease}\n".
                   "To: {$toRelease}\n".
                   'Time: '.date('Y-m-d H:i:s');

        $this->sendToAllChannels($message, 'warning');
    }

    /**
     * Check if any notification channels are configured
     */
    public function hasChannels(): bool
    {
        return ! empty($this->channels);
    }

    /**
     * Register default channels based on environment variables.
     */
    private function registerDefaultChannels(): void
    {
        $slackWebhook = $this->getEnv('DEPLOY_SLACK_WEBHOOK');
        if ($slackWebhook) {
            $this->channels[] = new SlackChannel($slackWebhook);
        }

        $discordWebhook = $this->getEnv('DEPLOY_DISCORD_WEBHOOK');
        if ($discordWebhook) {
            $this->channels[] = new DiscordChannel($discordWebhook);
        }
    }

    /**
     * Send notification to all registered channels.
     */
    private function sendToAllChannels(string $message, string $type): void
    {
        foreach ($this->channels as $channel) {
            // Silently fail - notifications shouldn't break deployment
            $channel->send($message, $type);
        }
    }

    /**
     * Format duration as human-readable string
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return number_format($seconds, 1).'s';
        }

        $minutes = (int) ($seconds / 60);
        $secs = (int) ($seconds % 60);

        return "{$minutes}m {$secs}s";
    }

    /**
     * Get environment variable
     */
    private function getEnv(string $key): ?string
    {
        return $_ENV[$key] ?? getenv($key) ?: null;
    }
}
