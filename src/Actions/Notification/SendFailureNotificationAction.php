<?php

namespace Shaf\LaravelDeployer\Actions\Notification;

use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Services\OutputService;

class SendFailureNotificationAction
{
    public function __construct(
        protected CommandExecutor $executor,
        protected OutputService $output,
        protected DeploymentConfig $config,
        protected string $errorMessage
    ) {
    }

    public function execute(): void
    {
        $this->output->error("Sending failure notification...");

        $message = "❌ Deployment failed: {$this->config->environment->value}\nError: {$this->errorMessage}";

        $this->output->error($message);

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
            'icon_emoji' => ':x:',
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
