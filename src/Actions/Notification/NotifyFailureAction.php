<?php

namespace Shaf\LaravelDeployer\Actions\Notification;

use Shaf\LaravelDeployer\Actions\AbstractAction;
use Shaf\LaravelDeployer\Services\NotificationService;

class NotifyFailureAction extends AbstractAction
{
    protected NotificationService $notificationService;

    public function __construct(\Shaf\LaravelDeployer\Deployer $deployer)
    {
        parent::__construct($deployer);
        $this->notificationService = new NotificationService($deployer);
    }

    public function execute(): void
    {
        $title = '❌ Deployment Failed';
        $application = $this->deployer->get('application', 'Application');
        $hostname = $this->deployer->get('hostname');
        $message = "{$application} deployment to {$hostname} failed. Check logs for details.";

        $this->notificationService->send($title, $message, false);
    }

    public function getName(): string
    {
        return 'notify:failure';
    }
}
