<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Data\DeploymentConfig;

/**
 * Notification action.
 * Handles sending deployment notifications to various channels.
 */
class NotificationAction
{
    public function __construct(
        private DeploymentConfig $config
    ) {}

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

        $this->sendNotification($message, 'success');
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

        $this->sendNotification($message, 'failure');
    }

    /**
     * Send notification to configured channels
     */
    private function sendNotification(string $message, string $type): void
    {
        // Check if Slack webhook is configured
        $slackWebhook = $this->getEnv('DEPLOY_SLACK_WEBHOOK');
        if ($slackWebhook) {
            $this->sendSlackNotification($slackWebhook, $message, $type);
        }

        // Check if Discord webhook is configured
        $discordWebhook = $this->getEnv('DEPLOY_DISCORD_WEBHOOK');
        if ($discordWebhook) {
            $this->sendDiscordNotification($discordWebhook, $message, $type);
        }

        // Add more notification channels here (Email, SMS, etc.)
    }

    /**
     * Send Slack notification
     */
    private function sendSlackNotification(string $webhook, string $message, string $type): void
    {
        try {
            $color = $type === 'success' ? 'good' : 'danger';

            $payload = json_encode([
                'attachments' => [
                    [
                        'color' => $color,
                        'text' => $message,
                        'footer' => 'Laravel Deployer',
                        'ts' => time(),
                    ],
                ],
            ]);

            $ch = curl_init($webhook);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $e) {
            // Silently fail - notifications shouldn't break deployment
        }
    }

    /**
     * Send Discord notification
     */
    private function sendDiscordNotification(string $webhook, string $message, string $type): void
    {
        try {
            $color = $type === 'success' ? 5763719 : 15548997; // Green or Red

            $payload = json_encode([
                'embeds' => [
                    [
                        'description' => $message,
                        'color' => $color,
                        'footer' => [
                            'text' => 'Laravel Deployer',
                        ],
                        'timestamp' => date('c'),
                    ],
                ],
            ]);

            $ch = curl_init($webhook);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $e) {
            // Silently fail - notifications shouldn't break deployment
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
