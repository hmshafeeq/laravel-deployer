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
     * Send success notification
     */
    public function success(array $data = []): void
    {
        $environment = $data['environment'] ?? $this->config->environment->value;
        $release = $data['release'] ?? 'unknown';
        $timestamp = date('Y-m-d H:i:s');

        $message = "✅ Deployment successful!\n".
                   "Environment: {$environment}\n".
                   "Release: {$release}\n".
                   "Time: {$timestamp}";

        $this->sendToAllChannels($message, 'success');
    }

    /**
     * Send failure notification
     */
    public function failure(\Exception $exception): void
    {
        $environment = $this->config->environment->value;
        $timestamp = date('Y-m-d H:i:s');

        $message = "❌ Deployment failed!\n".
                   "Environment: {$environment}\n".
                   "Error: {$exception->getMessage()}\n".
                   "Time: {$timestamp}";

        $this->sendToAllChannels($message, 'failure');
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
     * Get environment variable
     */
    private function getEnv(string $key): ?string
    {
        return $_ENV[$key] ?? getenv($key) ?: null;
    }
}
