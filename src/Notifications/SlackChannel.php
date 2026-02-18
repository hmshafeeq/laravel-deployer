<?php

namespace Shaf\LaravelDeployer\Notifications;

use Illuminate\Support\Facades\Http;
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

            return Http::timeout(10)
                ->post($this->webhook, [
                    'attachments' => [
                        [
                            'color' => $color,
                            'text' => $message,
                            'footer' => 'Laravel Deployer',
                            'ts' => time(),
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
        return 'Slack';
    }
}
