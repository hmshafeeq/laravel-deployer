<?php

namespace Shaf\LaravelDeployer\Deployer;

use Shaf\LaravelDeployer\Actions\Notification\SendSuccessNotificationAction;
use Shaf\LaravelDeployer\Actions\Notification\SendFailureNotificationAction;

class NotificationTasks
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
    }

    /**
     * Send success notification
     */
    public function success(): void
    {
        $this->deployer->task('notify:success', function () {
            SendSuccessNotificationAction::run($this->deployer);
        });
    }

    /**
     * Send failure notification
     */
    public function failure(): void
    {
        $this->deployer->task('notify:failure', function () {
            SendFailureNotificationAction::run($this->deployer);
        });
    }
}
