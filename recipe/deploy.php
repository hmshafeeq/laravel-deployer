<?php

namespace Deployer;

use Dotenv\Dotenv;

// Ensure Composer autoloader is loaded
$projectRoot = dirname(dirname(dirname(__DIR__)));
require_once $projectRoot.'/vendor/autoload.php';

// Import required recipes
require_once 'recipe/laravel.php';
require_once 'contrib/rsync.php';

set('rsync_src', getcwd());

// Load task files
require_once __DIR__.'/../tasks/database.php';
require_once __DIR__.'/../tasks/health.php';
require_once __DIR__.'/../tasks/logs.php';
require_once __DIR__.'/../tasks/notifications.php';
require_once __DIR__.'/../tasks/rollback.php';
require_once __DIR__.'/../tasks/system.php';

// Use timestamp for release name
set('release_name', function () {
    $yearMonth = date('Ym');
    $counterDir = '{{deploy_path}}/.dep/release_counter';
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

// Ensure deploy:env only runs once per deployment
set('deploy_env_loaded', false);

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
    $deployEnvFile = $projectRoot."/.deploy/.env.$environment";
    if (file_exists($deployEnvFile)) {
        $dotenv = Dotenv::createImmutable($projectRoot.'/.deploy', ".env.$environment");
        $dotenv->load();

        writeln("<info>✅ Loaded environment variables from .deploy/.env.$environment</info>");

        // Override host configuration with environment variables
        // $envPrefix = 'DEPLOY_' . strtoupper($environment) . '_';
        $envPrefix = 'DEPLOY_';

        if ($host = $_ENV[$envPrefix.'HOST'] ?? getenv($envPrefix.'HOST')) {
            set('hostname', $host);
            currentHost()->set('hostname', $host);
        }

        if ($user = $_ENV[$envPrefix.'USER'] ?? getenv($envPrefix.'USER')) {
            set('remote_user', $user);
            currentHost()->set('remote_user', $user);
        }

        if ($path = $_ENV[$envPrefix.'PATH'] ?? getenv($envPrefix.'PATH')) {
            set('deploy_path', $path);
            currentHost()->set('deploy_path', $path);
        }

        if ($branch = $_ENV[$envPrefix.'BRANCH'] ?? getenv($envPrefix.'BRANCH')) {
            set('branch', $branch);
            currentHost()->set('branch', $branch);
        }
    } else {
        writeln("<comment>⚠️  No .deploy/.env.$environment file found. Create one if you need environment variables.</comment>");
    }

    // Display loaded configuration
    $hostname = get('hostname');
    $user = get('remote_user');
    $path = get('deploy_path');

    //    writeln("<info>   Host: {$hostname}</info>");
    //    writeln("<info>   User: {$user}</info>");
    //    writeln("<info>   Path: {$path}</info>");

    writeln("<info>✅ Configuration loaded for environment: $environment</info>");

    // Mark as loaded to prevent duplicate runs
    set('deploy_env_loaded', true);

});

desc('Verify deployment target before proceeding');
task('deploy:confirm-target', function () {
    // Skip confirmation if DEPLOYER_NO_CONFIRM is set
    if (getenv('DEPLOYER_NO_CONFIRM') === '1') {
        writeln('<comment>⏭️  Skipping deployment confirmation (--no-confirm flag used)</comment>');
        writeln('');
        return;
    }

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

    $confirmed = askConfirmation('  Do you want to continue with this deployment?', true);

    if (! $confirmed) {
        writeln('');
        writeln('<comment>🛑 Deployment cancelled by user</comment>');
        writeln('');
        throw new \Exception('Deployment cancelled');
    }

    writeln('');
    writeln('<info>✓ Deployment confirmed, proceeding...</info>');
    writeln('');
})->desc('Confirm deployment target to prevent accidental deployments');

// Simple command tasks
task('build:assets', function () {
    runLocally('npm run build');
})->desc('Build frontend assets');

task('php-fpm:restart', function () {
    $environment = currentHost()->getAlias();

    if ($environment === 'local') {
        writeln('<comment>⏭️  Skipping PHP-FPM restart for local environment</comment>');

        return;
    }

    writeln('🔄 Restarting PHP-FPM...');

    // Detect all running PHP-FPM services
    $phpFpmServices = run('systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""');

    if (!empty(trim($phpFpmServices))) {
        $services = array_filter(explode("\n", trim($phpFpmServices)));

        foreach ($services as $service) {
            $service = trim($service);
            if (!empty($service)) {
                run("sudo systemctl restart {$service}");
                writeln("✅ Restarted {$service}");
            }
        }
    } else {
        writeln('<comment>⚠️  No running PHP-FPM service found</comment>');
    }
})->desc('Restart PHP-FPM service');

task('nginx:restart', function () {
    $environment = currentHost()->getAlias();

    if ($environment === 'local') {
        writeln('<comment>⏭️  Skipping Nginx restart for local environment</comment>');

        return;
    }

    writeln('🔄 Restarting Nginx...');
    run('sudo systemctl restart nginx');
    writeln('✅ Nginx restarted');
})->desc('Restart Nginx service');

task('supervisor:reload', function () {
    $environment = currentHost()->getAlias();

    if ($environment === 'local') {
        writeln('<comment>⏭️  Skipping Supervisor reload for local environment</comment>');

        return;
    }

    writeln('🔄 Reloading Supervisor...');
    try {
        run('sudo supervisorctl reload 2>/dev/null || echo "⚠️  Could not reload supervisor (passwordless sudo not configured)"');
        writeln('✅ Supervisor reload attempted');
    } catch (\Exception $e) {
        writeln('<comment>⚠️  Supervisor reload skipped (not critical)</comment>');
    }
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

task('deploy:fix-module-permissions', function () {
    $environment = currentHost()->getAlias();

    if ($environment === 'local') {
        writeln('<comment>⏭️  Skipping permission fix for local environment</comment>');

        return;
    }

    writeln('🔒 Fixing permissions for modular structure...');

    // Fix permissions for app-modules directory
    run('chmod -R 755 {{release_path}}/app-modules || true');
    run('find {{release_path}}/app-modules -type f -exec chmod 644 {} \; || true');
    run('find {{release_path}}/app-modules -type d -exec chmod 755 {} \; || true');

    writeln('✅ Module permissions fixed');
})->desc('Fix permissions for modular structure symlinks');

desc('Check if deployment is locked and prompt to unlock');
task('deploy:check-lock', function () {
    $lockFile = '{{deploy_path}}/.dep/deploy.lock';

    // Check if lock file exists
    if (test("[ -f $lockFile ]")) {
        // Read lock file content to get who locked it
        $lockContent = run("cat $lockFile 2>/dev/null || echo 'Unknown'");

        writeln('');
        writeln('<fg=red>═══════════════════════════════════════════════════════════</>');
        writeln('<fg=red>              DEPLOYMENT ALREADY LOCKED</>');
        writeln('<fg=red>═══════════════════════════════════════════════════════════</>');
        writeln('');
        writeln("  <comment>Locked by:</comment> <fg=yellow>{$lockContent}</>");
        writeln('');
        writeln('<fg=red>═══════════════════════════════════════════════════════════</>');
        writeln('');

        // Skip prompt if DEPLOYER_NO_CONFIRM is set
        if (getenv('DEPLOYER_NO_CONFIRM') === '1') {
            writeln('<fg=red>⏭️  Cannot unlock automatically with --no-confirm flag</fg>');
            writeln('<fg=red>   Please run: php artisan deploy {environment} deploy:unlock</fg>');
            writeln('');
            throw new \Exception('Deployment is locked. Unlock manually and try again.');
        }

        $unlock = askConfirmation('  Do you want to unlock and continue?', false);

        if ($unlock) {
            writeln('');
            writeln('<info>🔓 Unlocking deployment...</info>');
            invoke('deploy:unlock');
            writeln('<info>✓ Deployment unlocked successfully</info>');
            writeln('');
        } else {
            writeln('');
            writeln('<comment>🛑 Deployment cancelled. Deploy is locked.</comment>');
            writeln('<comment>   To unlock manually, run: php artisan deploy {environment} deploy:unlock</comment>');
            writeln('');
            throw new \Exception('Deployment is locked');
        }
    }
});

task('deploy:env-local', function () {
    $environment = currentHost()->getAlias();

    if ($environment !== 'local') {
        return;
    }

    writeln('📝 Setting up .env file for local deployment...');

    $projectRoot = runLocally('pwd');
    writeln($projectRoot);

    $projectEnvPath = trim($projectRoot).'/.env';
    $sharedEnvPath = '{{deploy_path}}/shared/.env';

    $envExists = test("[ -f {$sharedEnvPath} ]");

    if (! $envExists) {
        writeln('Copying .env file from project root...');

        // Check if project .env exists
        if (file_exists($projectEnvPath)) {
            run("cat > {$sharedEnvPath} << 'ENVEOF'\n".file_get_contents($projectEnvPath)."\nENVEOF");
            writeln('✅ .env file copied from project root');
        } else {
            writeln('⚠️  Project .env not found, creating basic .env...');
            run('echo "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n\nDB_CONNECTION=sqlite\nDB_DATABASE={{deploy_path}}/shared/database.sqlite\n\nCACHE_STORE=file\nQUEUE_CONNECTION=sync\nSESSION_DRIVER=file" > {{deploy_path}}/shared/.env');
            run('touch {{deploy_path}}/shared/database.sqlite');
            run('cd {{release_path}} && php artisan key:generate');
            writeln('✅ Basic .env file created');
        }
    } else {
        writeln('✅ .env file already exists');
    }
})->desc('Set up .env file for local deployment');

// Override artisan:queue:restart to skip for local
task('artisan:queue:restart', function () {
    $environment = currentHost()->getAlias();

    if ($environment === 'local') {
        writeln('<comment>⏭️  Skipping queue restart for local environment</comment>');

        return;
    }

    run('{{bin/php}} {{release_or_current_path}}/artisan queue:restart');
})->desc('Restart queue workers');

// Define deployment workflows
desc('Quick deployment (without database backup)');
task('deploy', [
    'deploy:env',
    'deploy:confirm-target',
    'deploy:info',
    'health:check-resources',
    'deploy:setup',
    'deploy:check-lock',
    'deploy:lock',
    'deploy:release',
    'build:assets',
    'rsync',
    'deploy:env-local',
    'deploy:shared',
    'deploy:writable',
    'deploy:vendors',
    'deploy:fix-module-permissions',
    'artisan:storage:link',
    'artisan:config:cache',
    'artisan:view:cache',
    'artisan:route:cache',
    'artisan:optimize',
    'artisan:migrate',
    'artisan:queue:restart',
    'php-fpm:restart',
    'nginx:restart',
    'supervisor:reload',
    'deploy:publish',
//    'health:check-endpoints',
    //    'logs:check',
    'deploy:link-dep',
    'notify:success',
]);

desc('Full deployment (with database backup)');
task('deploy:full', [
    'deploy:env',
    'deploy:confirm-target',
    'deploy:info',
    'health:check-resources',
    'deploy:setup',
    'deploy:check-lock',
    'deploy:lock',
    'deploy:release',
    'build:assets',
    'rsync',
    'deploy:shared',
    'deploy:env-local',
    'deploy:writable',
    'deploy:vendors',
    'deploy:fix-module-permissions',
    'artisan:storage:link',
    'artisan:config:cache',
    'artisan:view:cache',
    'artisan:route:cache',
    'artisan:optimize',
    'database:backup',
    'artisan:migrate',
    'artisan:queue:restart',
    'php-fpm:restart',
    'nginx:restart',
    'supervisor:reload',
    'deploy:publish',
//    'health:check-endpoints',
    //    'logs:check',
    'deploy:link-dep',
    'notify:success',
]);

// Auto-run deploy:env only for standalone tasks (not part of deploy workflow)
// Note: Tasks that are part of deploy:quick or deploy workflows should NOT be here
$standaloneTasksRequiringEnv = [
    'database:backup',  // Only when run standalone (not during deploy)
    'database:download',
    'logs:check',
    'logs:search',
    'logs:download',
    'rollback:quick',
    'rollback:full',
    'system:clear',
    'system:clear-cache',
    'system:restart',
];

foreach ($standaloneTasksRequiringEnv as $task) {
    before($task, 'deploy:env');
}

// Event handlers
after('deploy:failed', 'deploy:unlock');
after('deploy:failed', 'notify:failure');
