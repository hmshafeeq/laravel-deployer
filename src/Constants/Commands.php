<?php

namespace Shaf\LaravelDeployer\Constants;

class Commands
{
    public const RSYNC_FLAGS = 'rzc';

    public const RSYNC_SSH_OPTIONS = 'ssh -A -o ControlMaster=auto -o ControlPersist=60 -o ControlPath=/tmp/deployer-%r@%h:%p';

    public const RSYNC_OPTIONS = [
        'delete',
        'delete-after',
        'compress',
    ];
}
