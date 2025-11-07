<?php

namespace Shaf\LaravelDeployer\Deployer;

class NotificationTasks
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
    }

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

    public function success(): void
    {
        $this->deployer->task('notify:success', function ($deployer) {
            $title = '✅ Deployment Successful';
            $application = $deployer->get('application', 'Application');
            $releaseName = $deployer->getReleaseName();
            $hostname = $deployer->get('hostname');
            $message = "{$application} v{$releaseName} deployed successfully to {$hostname}";

            $this->sendNotification($title, $message, true);
        });
    }

    public function failure(): void
    {
        $this->deployer->task('notify:failure', function ($deployer) {
            $title = '❌ Deployment Failed';
            $application = $deployer->get('application', 'Application');
            $hostname = $deployer->get('hostname');
            $message = "{$application} deployment to {$hostname} failed. Check logs for details.";

            $this->sendNotification($title, $message, false);
        });
    }
}
