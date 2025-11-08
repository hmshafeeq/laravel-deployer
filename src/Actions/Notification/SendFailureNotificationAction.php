<?php

namespace Shaf\LaravelDeployer\Actions\Notification;

use Shaf\LaravelDeployer\Support\Abstract\NotificationAction;

class SendFailureNotificationAction extends NotificationAction
{
    public function execute(): void
    {
        $title = '❌ Deployment Failed';
        $application = $this->deployer->get('application', 'Application');
        $hostname = $this->deployer->get('hostname');
        $message = "{$application} deployment to {$hostname} failed. Check logs for details.";

        $this->sendNotification($title, $message, false);
    }
}
