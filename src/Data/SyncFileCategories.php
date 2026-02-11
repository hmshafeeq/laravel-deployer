<?php

namespace Shaf\LaravelDeployer\Data;

readonly class SyncFileCategories
{
    public function __construct(
        public bool $hasComposerLock = false,
        public bool $hasFrontendAssets = false,
        public bool $hasMigrations = false,
        public bool $hasNewFiles = false,
    ) {}

    /**
     * @param  array<string>  $files
     */
    public static function fromFileList(array $files): self
    {
        $hasComposerLock = false;
        $hasFrontendAssets = false;
        $hasMigrations = false;
        $hasNewFiles = false;

        foreach ($files as $file) {
            if ($file === 'composer.lock') {
                $hasComposerLock = true;
            }

            if (str_starts_with($file, 'resources/js/') ||
                str_starts_with($file, 'resources/css/') ||
                str_starts_with($file, 'resources/views/') ||
                str_ends_with($file, '.js') ||
                str_ends_with($file, '.css') ||
                str_ends_with($file, '.vue') ||
                str_ends_with($file, '.tsx') ||
                str_ends_with($file, '.jsx')) {
                $hasFrontendAssets = true;
            }

            if (str_starts_with($file, 'database/migrations/')) {
                $hasMigrations = true;
            }
        }

        return new self(
            hasComposerLock: $hasComposerLock,
            hasFrontendAssets: $hasFrontendAssets,
            hasMigrations: $hasMigrations,
            hasNewFiles: $hasNewFiles,
        );
    }

    /**
     * @param  array<string>  $statusLines  Lines from `git status --porcelain`
     */
    public static function fromGitStatus(array $statusLines): self
    {
        $hasNewFiles = false;

        foreach ($statusLines as $line) {
            $status = substr($line, 0, 2);
            if (str_contains($status, '?') || str_contains($status, 'A')) {
                $hasNewFiles = true;
                break;
            }
        }

        $files = array_map(fn (string $line) => trim(substr($line, 3)), $statusLines);

        $base = self::fromFileList($files);

        return new self(
            hasComposerLock: $base->hasComposerLock,
            hasFrontendAssets: $base->hasFrontendAssets,
            hasMigrations: $base->hasMigrations,
            hasNewFiles: $hasNewFiles,
        );
    }
}
