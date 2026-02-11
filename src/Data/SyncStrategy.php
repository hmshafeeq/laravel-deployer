<?php

namespace Shaf\LaravelDeployer\Data;

enum SyncStrategy: string
{
    case Full = 'full';
    case Dirty = 'dirty';
    case Since = 'since';
    case Branch = 'branch';

    public function isGitBased(): bool
    {
        return $this !== self::Full;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Full => 'Full rsync checksum scan',
            self::Dirty => 'Git uncommitted changes',
            self::Since => 'Git diff since commit',
            self::Branch => 'Git diff against branch',
        };
    }
}
