<?php

namespace Shaf\LaravelDeployer\Enums;

enum VerbosityLevel: string
{
    case QUIET = 'quiet';           // Only errors and critical messages
    case NORMAL = 'normal';         // Important messages and progress
    case VERBOSE = 'verbose';       // Include commands being executed (-v)
    case VERY_VERBOSE = 'very_verbose'; // Include command output (-vv)
    case DEBUG = 'debug';           // Everything including debug info (-vvv)

    public function shouldShow(VerbosityLevel $messageLevel): bool
    {
        $levels = [
            self::QUIET->value => 0,
            self::NORMAL->value => 1,
            self::VERBOSE->value => 2,
            self::VERY_VERBOSE->value => 3,
            self::DEBUG->value => 4,
        ];

        return $levels[$this->value] >= $levels[$messageLevel->value];
    }
}
