<?php

namespace Shaf\LaravelDeployer\Actions\Notification;

use Shaf\LaravelDeployer\Support\Abstract\NotificationAction;

class SendSuccessNotificationAction extends NotificationAction
{
    public function execute(): void
    {
        $title = '✅ Deployment Successful';
        $application = $this->deployer->get('application', 'Application');
        $releaseName = $this->deployer->getReleaseName();
        $hostname = $this->deployer->get('hostname');
        $message = "{$application} v{$releaseName} deployed successfully to {$hostname}";

        $this->sendNotification($title, $message, true);
    }
}
