<?php

namespace Shaf\LaravelDeployer\Constants;

class Commands
{
    public const RSYNC_FLAGS = 'rz';

    public const RSYNC_OPTIONS = [
        'delete',
        'delete-after',
        'compress',
    ];
}
