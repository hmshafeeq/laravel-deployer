<?php

namespace Deployer;

use Dotenv\Dotenv;

// Ensure Composer autoloader is loaded
$projectRoot = dirname(dirname(dirname(__DIR__)));
require_once $projectRoot . '/vendor/autoload.php';

// Import required recipes
require_once 'recipe/laravel.php';
require_once 'contrib/rsync.php';

set('rsync_src', getcwd());

// Load task files
require_once __DIR__ . '/../tasks/database.php';
require_once __DIR__ . '/../tasks/health.php';
require_once __DIR__ . '/../tasks/logs.php';
require_once __DIR__ . '/../tasks/notifications.php';
require_once __DIR__ . '/../tasks/rollback.php';

// Use timestamp for release name
set('release_name', function () {
    $yearMonth = date('Ym');
    $counterDir = "{{deploy_path}}/.dep/release_counter";
    $counterFile = "$counterDir/{$yearMonth}.txt";

    // Ensure the folder exists
    run("mkdir -p $counterDir");

    // Read counter or start from 0
    $count = run("if [ -f $counterFile ]; then cat $counterFile; else echo 0; fi");
    $count = (int) $count + 1;

    // Save updated counter
    run("echo $count > $counterFile");

    return "{$yearMonth}.{$count}";
});

// Auto-run deploy:env before tasks that need server access
$tasksRequiringEnv = [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:unlock',
    'health:check-resources',
    'health:check-endpoints',
    'database:backup',
    'database:download',
    'logs:check',
    'logs:view',
    'logs:search',
    'logs:download',
    'rollback:full',
];

// Ensure deploy:env only runs once per deployment
set('deploy_env_loaded', false);

foreach ($tasksRequiringEnv as $task) {
    before($task, 'deploy:env');
}

desc('Verify deployment target before proceeding');
task('deploy:confirm-target', function () {
    $environment = currentHost()->getAlias();
    $hostname = get('hostname');
    $deployPath = get('deploy_path');
    $user = get('remote_user');

    writeln('');
    writeln('<fg=yellow>═══════════════════════════════════════════════════════════</>');
    writeln('<fg=yellow>                 DEPLOYMENT CONFIRMATION</>');
    writeln('<fg=yellow>═══════════════════════════════════════════════════════════</>');
    writeln('');
    writeln("  <info>Environment:</info>  <fg=cyan>{$environment}</>");
    writeln("  <info>Server:</info>       <fg=cyan>{$hostname}</>");
    writeln("  <info>User:</info>         <fg=cyan>{$user}</>");
    writeln("  <info>Deploy Path:</info>  <fg=cyan>{$deployPath}</>");
    writeln('');

    // Extra warning for production
    if (strtolower($environment) === 'production' || strtolower($environment) === 'prod') {
        writeln('<fg=red>⚠️  WARNING: You are deploying to PRODUCTION!</>');
        writeln('');
    }

    writeln('<fg=yellow>═══════════════════════════════════════════════════════════</>');
    writeln('');

    $confirmed = askConfirmation('  Do you want to continue with this deployment?', false);

    if (!$confirmed) {
        writeln('');
        writeln('<comment>🛑 Deployment cancelled by user</comment>');
        writeln('');
        throw new \Exception('Deployment cancelled');
    }

    writeln('');
    writeln('<info>✓ Deployment confirmed, proceeding...</info>');
    writeln('');
})->desc('Confirm deployment target to prevent accidental deployments');

desc('Load deployment configuration from .deploy/ directory');
task('deploy:env', function () {

    // Skip if already loaded in this deployment
    if (get('deploy_env_loaded', false)) {
        return;
    }

    // Get project root directory (3 levels up from __DIR__)
    $projectRoot = dirname(dirname(dirname(__DIR__)));

    // Get current environment from host labels
    $environment = currentHost()->getAlias();

    // Load environment-specific .env file from .deploy directory
    $deployEnvFile = $projectRoot . "/.deploy/.env.$environment";
    if (file_exists($deployEnvFile)) {
        $dotenv = Dotenv::createImmutable($projectRoot . '/.deploy', ".env.$environment");
        $dotenv->load();

        writeln("<info>✅ Loaded environment variables from .deploy/.env.$environment</info>");
    } else {
        writeln("<comment>⚠️  No .deploy/.env.$environment file found. Create one if you need environment variables.</comment>");
    }


    // Load hosts.json configuration
    $hostsJsonPath = $projectRoot . '/.deploy/hosts.json';
    if (file_exists($hostsJsonPath)) {

        $deployConfig = json_decode(file_get_contents($hostsJsonPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in .deploy/hosts.json: ' . json_last_error_msg());
        }

        if (! isset($deployConfig[$environment])) {
            throw new \Exception("Environment '{$environment}' not found in .deploy/hosts.json");
        }


        $envConfig = $deployConfig[$environment];

        // Set deployer variables from JSON config
        set('hostname', $envConfig['hostname']);
        set('remote_user', $envConfig['remote_user']);
        set('deploy_path', $envConfig['deploy_path']);

        if (isset($envConfig['composer_options'])) {
            set('composer_options', $envConfig['composer_options']);
        }

        writeln("<info>✅ Configuration loaded for environment: $environment</info>");
        writeln("<info>   Host: {$envConfig['hostname']}</info>");
        writeln("<info>   User: {$envConfig['remote_user']}</info>");
        writeln("<info>   Path: {$envConfig['deploy_path']}</info>");
    }

    // Mark as loaded to prevent duplicate runs
    set('deploy_env_loaded', true);

});


// Simple command tasks
task('build:assets', function () {
    runLocally('npm run build');
})->desc('Build frontend assets');

task('php-fpm:restart', function () {
    run('sudo service php8.3-fpm restart');
})->desc('Restart PHP-FPM service');

task('supervisor:reload', function () {
    run('sudo supervisorctl reload');
})->desc('Reload Supervisor configuration');

task('cleanup:old-releases', function () {
    run('cd {{deploy_path}}/releases && ls -1t | tail -n +4 | xargs -r rm -rf');
})->desc('Clean up old releases');

task('rollback:quick', function () {
    run('cd {{current_path}} && php artisan queue:clear --quiet || true');
    invoke('deploy:rollback');
    run('cd {{current_path}} && php artisan config:clear');
    run('cd {{current_path}} && php artisan view:clear');
    run('cd {{current_path}} && php artisan route:clear');
    invoke('php-fpm:restart');
    invoke('artisan:queue:restart');
    writeln('Quick rollback completed');
})->desc('Quick rollback without database restore');

task('deploy:link-dep', function () {
    run('ln -sf {{deploy_path}}/.dep {{deploy_path}}/shared/storage/app/deployment');
})->desc('Create symlink to deployment directory');


// Define deployment workflows
desc('Quick deployment (without database backup)');
task('deploy:quick', [
    'deploy:confirm-target',
    'deploy:info',
    'health:check-resources',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'build:assets',
    'rsync',
    'deploy:shared',
    'deploy:writable',
    'deploy:vendors',
    'artisan:storage:link',
    'artisan:config:cache',
    'artisan:view:cache',
    'artisan:route:cache',
    'artisan:optimize',
    'artisan:migrate',
    'artisan:queue:restart',
    'php-fpm:restart',
    'supervisor:reload',
    'deploy:publish',
    'health:check-endpoints',
//    'logs:check',
    'deploy:link-dep',
    'notify:success',
]);

desc('Full deployment (with database backup)');
task('deploy', [
    'deploy:confirm-target',
    'deploy:info',
    'health:check-resources',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'build:assets',
    'rsync',
    'deploy:shared',
    'deploy:writable',
    'deploy:vendors',
    'artisan:storage:link',
    'artisan:config:cache',
    'artisan:view:cache',
    'artisan:route:cache',
    'artisan:optimize',
    'database:backup',
    'artisan:migrate',
    'artisan:queue:restart',
    'php-fpm:restart',
    'supervisor:reload',
    'deploy:publish',
    'health:check-endpoints',
//    'logs:check',
    'deploy:link-dep',
    'notify:success',
]);

// Event handlers
after('deploy:failed', 'deploy:unlock');
after('deploy:failed', 'notify:failure');