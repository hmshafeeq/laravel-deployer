<?php

namespace Shaf\LaravelDeployer\Actions\Notification;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;

class SendSuccessNotificationAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config,
        protected string $releaseName
    ) {
    }

    public function execute(): void
    {
        $this->output->info("Sending success notification...");

        // You can customize this to send to Slack, email, etc.
        // For now, just log it
        $message = "✅ Deployment successful: {$this->config->environment->value} - Release: {$this->releaseName}";

        $this->output->success($message);

        // Example: Send to Slack webhook if configured
        // if ($webhookUrl = env('DEPLOYMENT_SLACK_WEBHOOK')) {
        //     $this->sendToSlack($webhookUrl, $message);
        // }
    }

    protected function sendToSlack(string $webhookUrl, string $message): void
    {
        $payload = json_encode([
            'text' => $message,
            'username' => 'Deployment Bot',
            'icon_emoji' => ':rocket:',
        ]);

        $cmd = sprintf(
            'curl -X POST -H "Content-Type: application/json" -d %s %s',
            escapeshellarg($payload),
            escapeshellarg($webhookUrl)
        );

        try {
            $this->executor->execute($cmd);
        } catch (\Exception $e) {
            $this->output->warn("Failed to send Slack notification: " . $e->getMessage());
        }
    }
}
