<?php

namespace Deployer;

function sendNotification(string $title, string $message, bool $isSuccess = true): void
{
    $sound = $isSuccess ? 'Glass' : 'Basso';
    $icon = $isSuccess ? 'dialog-information' : 'dialog-error';
    $color = $isSuccess ? '28a745' : 'dc3545';
    $emoji = $isSuccess ? '✓' : '✗';

    if (PHP_OS_FAMILY === 'Darwin') {
        runLocally("osascript -e 'display notification \"{$message}\" with title \"{$title}\" sound name \"{$sound}\"'");
    } elseif (PHP_OS_FAMILY === 'Linux') {
        runLocally("command -v notify-send >/dev/null 2>&1 && notify-send \"{$title}\" \"{$message}\" --icon={$icon} || true");
    } elseif (PHP_OS_FAMILY === 'Windows') {
        $powershell = "New-BurntToastNotification -Text '{$title}', '{$message}' -AppLogo 'https://via.placeholder.com/64x64/{$color}/ffffff?text={$emoji}'";
        runLocally("powershell -Command \"try { {$powershell} } catch { Write-Host 'Notification failed' }\"");
    }
}

task('notify:success', function () {
    $title = '✅ Deployment Successful';
    $message = get('application') . ' v' . get('release_name') . ' deployed successfully to ' . get('hostname');
    sendNotification($title, $message, true);
})->desc('Send success notification to local system');

task('notify:failure', function () {
    $title = '❌ Deployment Failed';
    $message = get('application') . ' deployment to ' . get('hostname') . ' failed. Check logs for details.';
    sendNotification($title, $message, false);
})->desc('Send failure notification to local system');