<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer;

/**
 * Base class for notification actions
 */
abstract class NotificationAction extends Action
{
    public function __construct(
        protected Deployer $deployer
    ) {}

    /**
     * Send a notification across different platforms
     */
    protected function sendNotification(string $title, string $message, bool $isSuccess = true): void
    {
        $sound = $isSuccess ? 'Glass' : 'Basso';

        if (PHP_OS_FAMILY === 'Darwin') {
            $this->deployer->runLocally("osascript -e 'display notification \"{$message}\" with title \"{$title}\" sound name \"{$sound}\"'");
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $icon = $isSuccess ? 'dialog-information' : 'dialog-error';
            $this->deployer->runLocally("command -v notify-send >/dev/null 2>&1 && notify-send \"{$title}\" \"{$message}\" --icon={$icon} || true");
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $color = $isSuccess ? '28a745' : 'dc3545';
            $emoji = $isSuccess ? '✓' : '✗';
            $powershell = "New-BurntToastNotification -Text '{$title}', '{$message}' -AppLogo 'https://via.placeholder.com/64x64/{$color}/ffffff?text={$emoji}'";
            $this->deployer->runLocally("powershell -Command \"try { {$powershell} } catch { Write-Host 'Notification failed' }\"");
        }
    }
}
