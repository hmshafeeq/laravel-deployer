<?php

namespace Shaf\LaravelDeployer\Actions\Notification;

use Shaf\LaravelDeployer\Actions\AbstractAction;
use Shaf\LaravelDeployer\Services\NotificationService;

class NotifySuccessAction extends AbstractAction
{
    protected NotificationService $notificationService;

    public function __construct(\Shaf\LaravelDeployer\Deployer $deployer)
    {
        parent::__construct($deployer);
        $this->notificationService = new NotificationService($deployer);
    }

    public function execute(): void
    {
        $title = '✅ Deployment Successful';
        $application = $this->deployer->get('application', 'Application');
        $releaseName = $this->deployer->getReleaseName();
        $hostname = $this->deployer->get('hostname');
        $message = "{$application} v{$releaseName} deployed successfully to {$hostname}";

        $this->notificationService->send($title, $message, true);
    }

    public function getName(): string
    {
        return 'notify:success';
    }
}
