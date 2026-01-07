<?php

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Services\CommandService;
use Symfony\Component\Console\Output\NullOutput;

dataset('escapePathData', [
    'simple path' => ['/var/www/app', "'/var/www/app'"],
    'path with spaces' => ['/var/www/my app', "'/var/www/my app'"],
    'path with single quotes' => ["/var/www/app's", "'/var/www/app'\\''s'"],
    'path with special chars' => ['/var/www/app$(dangerous)', "'/var/www/app\$(dangerous)'"],
    'empty path' => ['', "''"],
]);

test('escapePath() handles paths with spaces', function ($input, $expected) {
    expect(CommandService::escapePath($input))->toBe($expected);
})->with('escapePathData');

dataset('maskSecretsData', [
    'MySQL password with quotes' => [
        "mysql -u root -p'secret123' database",
        "mysql -u root -p'***' database",
    ],
    'MySQL password without quotes' => [
        'mysql -u root -psecret123 database',
        'mysql -u root -p*** database',
    ],
    'GitHub classic PAT' => [
        'git clone https://ghp_1234567890abcdef@github.com/user/repo.git',
        'git clone https://gh*_***@github.com/user/repo.git',
    ],
    'GitHub fine-grained PAT' => [
        'git clone https://github_pat_abcdef123456@github.com/user/repo.git',
        'git clone https://github_pat_***@github.com/user/repo.git',
    ],
    'Slack webhook URL' => [
        'curl https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX',
        'curl https://hooks.slack.com/services/***',
    ],
    'Discord webhook URL' => [
        'curl https://discord.com/api/webhooks/1234567890123456789/abcdef1234567890abcdef1234567890abcdef1234567890',
        'curl https://discord.com/api/webhooks/***',
    ],
    'COMPOSER_AUTH JSON' => [
        'COMPOSER_AUTH=\'{"github-oauth":{"github.com":"ghp_1234567890abcdef"}}\' composer install',
        'COMPOSER_AUTH=\'***\' composer install',
    ],
    'generic PASSWORD env var' => [
        'PASSWORD=secret123 node app.js',
        'PASSWORD=*** node app.js',
    ],
    'no secrets to mask' => [
        'echo "Hello World"',
        'echo "Hello World"',
    ],
]);

test('maskSecrets() masks various secret patterns', function ($input, $expected) {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist'
    );

    $service = new CommandService($config, new NullOutput);
    $result = invokePrivateMethod($service, 'maskSecrets', [$input]);

    expect($result)->toBe($expected);
})->with('maskSecretsData');

dataset('parseMigrationOutputData', [
    'standard migration output' => [
        "Migrating: 2025_01_15_000001_create_users_table\nMigrated:  2025_01_15_000001_create_users_table (1.23ms)\nMigrating: 2025_01_15_000002_create_posts_table\nMigrated:  2025_01_15_000002_create_posts_table (0.89ms)",
        [
            'output' => "Migrating: 2025_01_15_000001_create_users_table\nMigrated:  2025_01_15_000001_create_users_table (1.23ms)\nMigrating: 2025_01_15_000002_create_posts_table\nMigrated:  2025_01_15_000002_create_posts_table (0.89ms)",
            'count' => 2,
            'migrations' => ['2025_01_15_000001_create_users_table', '2025_01_15_000002_create_posts_table'],
        ],
    ],
    'nothing to migrate' => [
        'Nothing to migrate.',
        [
            'output' => 'Nothing to migrate.',
            'count' => 0,
            'migrations' => [],
        ],
    ],
    'legacy format' => [
        "Running migration: 2025_01_15_000001_create_users_table ... DONE\nRunning migration: 2025_01_15_000002_create_posts_table ... DONE",
        [
            'output' => "Running migration: 2025_01_15_000001_create_users_table ... DONE\nRunning migration: 2025_01_15_000002_create_posts_table ... DONE",
            'count' => 2,
            'migrations' => ['2025_01_15_000001_create_users_table', '2025_01_15_000002_create_posts_table'],
        ],
    ],
    'no migrations found' => [
        'Some other output without migration patterns',
        [
            'output' => 'Some other output without migration patterns',
            'count' => 0,
            'migrations' => [],
        ],
    ],
    'duplicate migrations removed' => [
        "Migrating: 2025_01_15_000001_create_users_table\nMigrated:  2025_01_15_000001_create_users_table\nRunning migration: 2025_01_15_000001_create_users_table ... DONE",
        [
            'output' => "Migrating: 2025_01_15_000001_create_users_table\nMigrated:  2025_01_15_000001_create_users_table\nRunning migration: 2025_01_15_000001_create_users_table ... DONE",
            'count' => 1,
            'migrations' => ['2025_01_15_000001_create_users_table'],
        ],
    ],
]);

test('parseMigrationOutput() extracts migration names from output', function ($input, $expected) {
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www',
        composerOptions: '--prefer-dist'
    );

    $service = new CommandService($config, new NullOutput);
    $result = invokePrivateMethod($service, 'parseMigrationOutput', [$input]);

    expect($result)->toBe($expected);
})->with('parseMigrationOutputData');
