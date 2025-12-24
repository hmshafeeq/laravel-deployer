<?php

namespace Shaf\LaravelDeployer\Notifications;

use Shaf\LaravelDeployer\Contracts\NotificationChannel;

/**
 * Discord notification channel.
 */
class DiscordChannel implements NotificationChannel
{
    private const COLOR_SUCCESS = 5763719;  // Green

    private const COLOR_FAILURE = 15548997; // Red

    public function __construct(
        private string $webhook
    ) {}

    public function send(string $message, string $type): bool
    {
        try {
            $color = $type === 'success' ? self::COLOR_SUCCESS : self::COLOR_FAILURE;

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
        return 'Discord';
    }
}
