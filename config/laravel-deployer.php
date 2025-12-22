<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PHP Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the PHP executable path and timeout settings for deployment
    | operations.
    |
    */
    'php' => [
        'executable' => env('DEPLOY_PHP_PATH', '/usr/bin/php'),
        'timeout' => env('DEPLOY_PHP_TIMEOUT', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployment Paths
    |--------------------------------------------------------------------------
    |
    | Configure deployment path settings including number of releases to keep
    | and writable directories that need proper permissions.
    |
    */
    'paths' => [
        'keep_releases' => env('DEPLOY_KEEP_RELEASES', 3),
        'writable_dirs' => [
            'bootstrap/cache',
            'storage',
            'storage/app',
            'storage/app/public',
            'storage/framework',
            'storage/framework/cache',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Composer Configuration
    |--------------------------------------------------------------------------
    |
    | Configure Composer installation options used during deployment.
    |
    */
    'composer' => [
        'options' => env(
            'DEPLOY_COMPOSER_OPTIONS',
            '--verbose --prefer-dist --no-interaction --no-scripts --optimize-autoloader'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rsync Configuration
    |--------------------------------------------------------------------------
    |
    | Configure rsync settings for file synchronization during deployment.
    |
    */
    'rsync' => [
        'timeout' => env('DEPLOY_RSYNC_TIMEOUT', 900),
        'ssh_options' => "-e 'ssh -A -o ControlMaster=auto -o ControlPersist=60'",
        'flags' => '-rzc --delete --delete-after --compress',
        'excludes' => [
            '.git',
            'node_modules',
            '.env',
            'tests',
            '.deploy',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Backup Configuration
    |--------------------------------------------------------------------------
    |
    | Configure database backup settings including storage path, retention
    | policy, and timeout settings.
    |
    */
    'backup' => [
        'path' => 'shared/backups',
        'keep' => env('DEPLOY_BACKUP_KEEP', 3),
        'timeout' => env('DEPLOY_BACKUP_TIMEOUT', 1800),
        'compression_level' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Configuration
    |--------------------------------------------------------------------------
    |
    | Configure health check settings including retry logic and endpoints
    | to verify after deployment.
    |
    */
    'health_check' => [
        'enabled' => env('DEPLOY_HEALTH_CHECK', true),
        'max_retries' => env('DEPLOY_HEALTH_CHECK_RETRIES', 3),
        'retry_delay' => env('DEPLOY_HEALTH_CHECK_DELAY', 5), // seconds
        'timeout' => env('DEPLOY_HEALTH_CHECK_TIMEOUT', 30),
        'connect_timeout' => env('DEPLOY_HEALTH_CHECK_CONNECT_TIMEOUT', 5),
        'endpoints' => [
            '/' => 'Home page',
            '/admin/login' => 'Admin login',
            '/user/login' => 'User login',
            '/health' => 'Health check',
        ],
        'acceptable_status_codes' => [200, 302, 401],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Check Thresholds
    |--------------------------------------------------------------------------
    |
    | Configure server resource check thresholds for disk space and memory.
    |
    */
    'resources' => [
        'disk' => [
            'critical_threshold' => 90, // percent
            'warning_threshold' => 80,  // percent
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Configuration
    |--------------------------------------------------------------------------
    |
    | Configure output formatting including color codes for different
    | message types.
    |
    */
    'output' => [
        'colors' => [
            'info' => "\033[32m",    // green
            'comment' => "\033[33m", // yellow
            'error' => "\033[31m",   // red
            'plain' => '',
        ],
        'reset' => "\033[0m",
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Configuration
    |--------------------------------------------------------------------------
    |
    | Configure notification settings for deployment success/failure alerts.
    |
    */
    'notifications' => [
        'enabled' => env('DEPLOY_NOTIFICATIONS', true),
        'sounds' => [
            'success' => 'Glass',
            'failure' => 'Basso',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix used for environment variable overrides in .deploy/.env files.
    |
    */
    'env_prefix' => 'DEPLOY_',

    /*
    |--------------------------------------------------------------------------
    | Services Configuration
    |--------------------------------------------------------------------------
    |
    | Services to restart after deployment. Set to false to disable restart
    | for a specific service.
    |
    */
    'services' => [
        'php-fpm' => env('DEPLOY_RESTART_PHP_FPM', true),
        'nginx' => env('DEPLOY_RESTART_NGINX', true),
        'supervisor' => env('DEPLOY_RESTART_SUPERVISOR', true),
    ],
];
