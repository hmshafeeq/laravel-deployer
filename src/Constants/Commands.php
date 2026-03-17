<?php

namespace Shaf\LaravelDeployer\Constants;

class Commands
{
    public const RSYNC_FLAGS = 'rzc';

    public const RSYNC_OPTIONS = [
        'delete',
        'delete-after',
        'compress',
    ];
}
