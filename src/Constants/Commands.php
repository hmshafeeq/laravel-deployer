<?php

namespace Shaf\LaravelDeployer\Constants;

class Commands
{
    public const PHP_BINARY = '/usr/bin/php';

    public const COMPOSER_BINARY = 'composer';

    public const DEFAULT_COMPOSER_OPTIONS = '--verbose --prefer-dist --no-interaction --no-scripts --optimize-autoloader';

    public const PRODUCTION_COMPOSER_OPTIONS = '--no-dev --optimize-autoloader --prefer-dist --no-interaction';

    public const RSYNC_FLAGS = 'rzc';

    public const RSYNC_SSH_OPTIONS = 'ssh -A -o ControlMaster=auto -o ControlPersist=60';

    public const RSYNC_OPTIONS = [
        'delete',
        'delete-after',
        'compress',
    ];
}
