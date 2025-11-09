<?php

namespace Shaf\LaravelDeployer\Deployer;

use Symfony\Component\Process\Process;

class NotificationTasks extends BaseTaskRunner
{
    protected function sendNotification(string $title, string $message, bool $isSuccess = true): void
    {
        if ($this->isLocal()) {
            $this->sendLocalNotification($title, $message, $isSuccess);
        }
    }

    protected function sendLocalNotification(string $title, string $message, bool $isSuccess): void
    {
        $sound = $isSuccess ? 'Glass' : 'Basso';

        if (PHP_OS_FAMILY === 'Darwin') {
            $command = "osascript -e 'display notification \"{$message}\" with title \"{$title}\" sound name \"{$sound}\"'";
            $this->runLocalCommand($command);
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $icon = $isSuccess ? 'dialog-information' : 'dialog-error';
            $command = "command -v notify-send >/dev/null 2>&1 && notify-send \"{$title}\" \"{$message}\" --icon={$icon} || true";
            $this->runLocalCommand($command);
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $color = $isSuccess ? '28a745' : 'dc3545';
            $emoji = $isSuccess ? '✓' : '✗';
            $powershell = "New-BurntToastNotification -Text '{$title}', '{$message}' -AppLogo 'https://via.placeholder.com/64x64/{$color}/ffffff?text={$emoji}'";
            $command = "powershell -Command \"try { {$powershell} } catch { Write-Host 'Notification failed' }\"";
            $this->runLocalCommand($command);
        }
    }

    protected function runLocalCommand(string $command): void
    {
        try {
            $process = Process::fromShellCommandline($command);
            $process->run();
        } catch (\Exception $e) {
            $this->output->debug("Notification failed: {$e->getMessage()}");
        }
    }

    public function success(): void
    {
        $this->task('notify:success', function () {
            $title = '✅ Deployment Successful';
            $application = $this->getApplicationName();
            $releaseName = $this->getReleaseName();
            $hostname = $this->getHostname();
            $message = "{$application} v{$releaseName} deployed successfully to {$hostname}";

            $this->sendNotification($title, $message, true);
        });
    }

    public function failure(): void
    {
        $this->task('notify:failure', function () {
            $title = '❌ Deployment Failed';
            $application = $this->getApplicationName();
            $hostname = $this->getHostname();
            $message = "{$application} deployment to {$hostname} failed. Check logs for details.";

            $this->sendNotification($title, $message, false);
        });
    }
}
