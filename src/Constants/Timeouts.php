<?php

namespace Shaf\LaravelDeployer\Constants;

class Timeouts
{
    public const DEFAULT_COMMAND = 900; // 15 minutes

    public const RSYNC = 900; // 15 minutes

    public const COMPOSER_INSTALL = 600; // 10 minutes

    public const NPM_BUILD = 600; // 10 minutes

    public const HEALTH_CHECK = 30; // 30 seconds

    public const HEALTH_CHECK_RETRY_DELAY = 5; // 5 seconds

    public const MAX_HEALTH_CHECK_RETRIES = 3;
}
