<?php

namespace Deployer;

// ============================================================================
// System Management Tasks
// ============================================================================

desc('Clear all caches and restart services');
task('system:clear', function () {
    $environment = currentHost()->getAlias();

    writeln('🧹 Starting system clear process...');
    writeln('');

    // Run composer clear script
    writeln('📦 Running composer clear script...');
    run('cd {{current_path}} && composer run clear');
    writeln('✅ Composer clear completed');
    writeln('');

    // Skip service restarts for local environment
    if ($environment === 'local') {
        writeln('<comment>⏭️  Skipping service restarts for local environment</comment>');
        writeln('✅ System clear completed!');

        return;
    }

    // Restart supervisor
    writeln('🔄 Reloading Supervisor...');
    run('sudo supervisorctl reload 2>/dev/null || echo "⚠️  Could not reload supervisor (passwordless sudo not configured)"');
    writeln('✅ Supervisor reload attempted');
    writeln('');

    // Restart queue workers
    writeln('🔄 Restarting queue workers...');
    run('cd {{current_path}} && php artisan queue:restart');
    writeln('✅ Queue workers restarted');
    writeln('');

    writeln('✅ System clear completed successfully!');
})->desc('Clear all caches and restart services (composer clear, supervisor reload, queue restart)');

desc('Clear Laravel caches only');
task('system:clear-cache', function () {
    writeln('🧹 Clearing Laravel caches...');

    run('cd {{current_path}} && php artisan cache:clear');
    run('cd {{current_path}} && php artisan config:clear');
    run('cd {{current_path}} && php artisan route:clear');
    run('cd {{current_path}} && php artisan view:clear');

    writeln('✅ Laravel caches cleared!');
})->desc('Clear Laravel caches only (cache, config, route, view)');

desc('Restart all services');
task('system:restart', function () {
    $environment = currentHost()->getAlias();

    if ($environment === 'local') {
        writeln('<comment>⏭️  Skipping service restarts for local environment</comment>');

        return;
    }

    writeln('🔄 Restarting services...');
    writeln('');

    // Restart PHP-FPM
    writeln('🔄 Restarting PHP-FPM...');
    run('sudo service php8.3-fpm restart');
    writeln('✅ PHP-FPM restarted');
    writeln('');

    // Restart supervisor
    writeln('🔄 Reloading Supervisor...');
    run('sudo supervisorctl reload 2>/dev/null || echo "⚠️  Could not reload supervisor (passwordless sudo not configured)"');
    writeln('✅ Supervisor reload attempted');
    writeln('');

    // Restart queue workers
    writeln('🔄 Restarting queue workers...');
    run('cd {{current_path}} && php artisan queue:restart');
    writeln('✅ Queue workers restarted');
    writeln('');

    writeln('✅ All services restarted successfully!');
})->desc('Restart all services (PHP-FPM, Supervisor, Queue workers)');
