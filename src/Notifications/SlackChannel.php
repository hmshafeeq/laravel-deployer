<?php

namespace Shaf\LaravelDeployer\Notifications;

use Shaf\LaravelDeployer\Contracts\NotificationChannel;

/**
 * Slack notification channel.
 */
class SlackChannel implements NotificationChannel
{
    public function __construct(
        private string $webhook
    ) {}

    public function send(string $message, string $type): bool
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

            $ch = curl_init($this->webhook);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $result = curl_exec($ch);
            $success = curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
            curl_close($ch);

            return $success && $result !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getName(): string
    {
        return 'Slack';
    }
}
