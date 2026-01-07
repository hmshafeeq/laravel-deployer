<?php

use Illuminate\Support\Facades\Http;
use Shaf\LaravelDeployer\Actions\NotificationAction;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Enums\Environment;

beforeEach(function () {
    $this->config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist'
    );
});

test('success() sends notification to configured channels', function () {
    // Mock environment variables for webhooks
    $_ENV['DEPLOY_SLACK_WEBHOOK'] = 'https://hooks.slack.com/services/TEST/SLACK';
    $_ENV['DEPLOY_DISCORD_WEBHOOK'] = 'https://discord.com/api/webhooks/TEST/DISCORD';

    Http::fake();

    $action = new NotificationAction($this->config);

    $action->success([
        'release' => '202501.1',
        'duration' => 120.5,
        'gitInfo' => [
            'branch' => 'main',
            'commit' => 'abcdef',
            'author' => 'John Doe',
        ],
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.slack.com/services/TEST/SLACK'
            && str_contains($request['attachments'][0]['text'], 'Deployment to production successful')
            && str_contains($request['attachments'][0]['text'], 'Release: 202501.1 (main @ abcdef)')
            && str_contains($request['attachments'][0]['text'], 'Duration: 2m 0.5s');
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'https://discord.com/api/webhooks/TEST/DISCORD'
            && str_contains($request['embeds'][0]['description'], 'Deployment to production successful');
    });

    // Cleanup env
    unset($_ENV['DEPLOY_SLACK_WEBHOOK'], $_ENV['DEPLOY_DISCORD_WEBHOOK']);
});

test('failure() sends error details', function () {
    $_ENV['DEPLOY_SLACK_WEBHOOK'] = 'https://hooks.slack.com/services/TEST/SLACK';
    Http::fake();

    $action = new NotificationAction($this->config);
    $exception = new Exception('Connection timeout');

    $action->failure($exception, [
        'failedStep' => 'files:sync',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request['attachments'][0]['text'], 'Deployment to production failed')
            && str_contains($request['attachments'][0]['text'], 'Error: Connection timeout')
            && str_contains($request['attachments'][0]['text'], 'Failed at: files:sync');
    });

    unset($_ENV['DEPLOY_SLACK_WEBHOOK']);
});

test('rollback() sends warning notification', function () {
    $_ENV['DEPLOY_SLACK_WEBHOOK'] = 'https://hooks.slack.com/services/TEST/SLACK';
    Http::fake();

    $action = new NotificationAction($this->config);
    $action->rollback('202501.2', '202501.1');

    Http::assertSent(function ($request) {
        return str_contains($request['attachments'][0]['text'], 'Rollback on production')
            && str_contains($request['attachments'][0]['text'], 'From: 202501.2')
            && str_contains($request['attachments'][0]['text'], 'To: 202501.1');
    });

    unset($_ENV['DEPLOY_SLACK_WEBHOOK']);
});
