<?php

namespace Shaf\LaravelDeployer\Constants;

class Paths
{
    public const DEP_DIR = '.dep';
    public const RELEASES_DIR = 'releases';
    public const SHARED_DIR = 'shared';
    public const CURRENT_SYMLINK = 'current';
    public const RELEASE_SYMLINK = 'release';

    public const LOCK_FILE = self::DEP_DIR . '/deploy.lock';
    public const RELEASES_LOG = self::DEP_DIR . '/releases_log';
    public const LATEST_RELEASE = self::DEP_DIR . '/latest_release';
    public const COUNTER_DIR = self::DEP_DIR . '/release_counter';

    public const SHARED_STORAGE = self::SHARED_DIR . '/storage';
    public const SHARED_ENV = self::SHARED_DIR . '/.env';

    public const WRITABLE_DIRS = [
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
    ];
}
