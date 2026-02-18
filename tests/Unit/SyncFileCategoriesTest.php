<?php

use Shaf\LaravelDeployer\Data\SyncFileCategories;

test('fromFileList() detects composer.lock', function () {
    $categories = SyncFileCategories::fromFileList(['app/Models/User.php', 'composer.lock']);

    expect($categories->hasComposerLock)->toBeTrue();
    expect($categories->hasFrontendAssets)->toBeFalse();
    expect($categories->hasMigrations)->toBeFalse();
});

test('fromFileList() detects frontend assets', function () {
    $files = ['resources/js/app.js', 'resources/css/app.css'];
    $categories = SyncFileCategories::fromFileList($files);

    expect($categories->hasFrontendAssets)->toBeTrue();
});

test('fromFileList() detects blade views as frontend assets', function () {
    $categories = SyncFileCategories::fromFileList(['resources/views/welcome.blade.php']);

    expect($categories->hasFrontendAssets)->toBeTrue();
});

test('fromFileList() detects Vue/React files as frontend assets', function () {
    expect(SyncFileCategories::fromFileList(['app.vue'])->hasFrontendAssets)->toBeTrue();
    expect(SyncFileCategories::fromFileList(['app.tsx'])->hasFrontendAssets)->toBeTrue();
    expect(SyncFileCategories::fromFileList(['app.jsx'])->hasFrontendAssets)->toBeTrue();
});

test('fromFileList() detects migrations', function () {
    $categories = SyncFileCategories::fromFileList([
        'database/migrations/2024_01_01_000000_create_users_table.php',
    ]);

    expect($categories->hasMigrations)->toBeTrue();
});

test('fromFileList() returns all false for non-special files', function () {
    $categories = SyncFileCategories::fromFileList([
        'app/Models/User.php',
        'app/Http/Controllers/UserController.php',
    ]);

    expect($categories->hasComposerLock)->toBeFalse();
    expect($categories->hasFrontendAssets)->toBeFalse();
    expect($categories->hasMigrations)->toBeFalse();
    expect($categories->hasNewFiles)->toBeFalse();
});

test('fromGitStatus() detects new files from status', function () {
    $statusLines = [
        '?? app/Models/NewModel.php',
        ' M app/Models/User.php',
    ];

    $categories = SyncFileCategories::fromGitStatus($statusLines);

    expect($categories->hasNewFiles)->toBeTrue();
});

test('fromGitStatus() detects added files', function () {
    $statusLines = [
        'A  app/Models/NewModel.php',
    ];

    $categories = SyncFileCategories::fromGitStatus($statusLines);

    expect($categories->hasNewFiles)->toBeTrue();
});

test('fromGitStatus() no new files when only modifications', function () {
    $statusLines = [
        ' M app/Models/User.php',
        'M  config/app.php',
    ];

    $categories = SyncFileCategories::fromGitStatus($statusLines);

    expect($categories->hasNewFiles)->toBeFalse();
});
