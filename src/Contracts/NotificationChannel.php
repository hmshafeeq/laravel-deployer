<?php

namespace Shaf\LaravelDeployer\Contracts;

/**
 * Interface for notification channels.
 * Implement this to add new notification providers (Teams, Email, SMS, etc.).
 */
interface NotificationChannel
{
    /**
     * Send a notification message.
     *
     * @param  string  $message  The notification message
     * @param  string  $type  The notification type ('success' or 'failure')
     * @return bool True if sent successfully, false otherwise
     */
    public function send(string $message, string $type): bool;

    /**
     * Get the channel name for logging purposes.
     */
    public function getName(): string;
}
