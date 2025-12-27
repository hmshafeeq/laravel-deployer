<?php

namespace Shaf\LaravelDeployer\Notifications;

use Illuminate\Support\Facades\Http;
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

            return Http::timeout(10)
                ->post($this->webhook, [
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
                ])
                ->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getName(): string
    {
        return 'Discord';
    }
}
