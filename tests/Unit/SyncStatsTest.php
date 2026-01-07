<?php

use Shaf\LaravelDeployer\Data\SyncStats;

test('empty() returns SyncStats with all zeros', function () {
    $stats = SyncStats::empty();

    expect($stats->filesSynced)->toBe(0);
    expect($stats->filesAdded)->toBe(0);
    expect($stats->filesModified)->toBe(0);
    expect($stats->filesDeleted)->toBe(0);
    expect($stats->bytesTransferred)->toBe(0);
});

test('hasChanges() returns false for empty stats', function () {
    $stats = SyncStats::empty();
    expect($stats->hasChanges())->toBeFalse();
});

test('hasChanges() returns true when filesSynced > 0', function () {
    $stats = new SyncStats(filesSynced: 1);
    expect($stats->hasChanges())->toBeTrue();
});

test('hasChanges() returns true when bytesTransferred > 0', function () {
    $stats = new SyncStats(bytesTransferred: 1024);
    expect($stats->hasChanges())->toBeTrue();
});

test('getFormattedSize() formats bytes correctly', function () {
    $stats = new SyncStats(bytesTransferred: 1024);
    expect($stats->getFormattedSize())->toBe('1 KB');

    $stats = new SyncStats(bytesTransferred: 1024 * 1024);
    expect($stats->getFormattedSize())->toBe('1 MB');
});

test('toArray() returns correct structure', function () {
    $stats = new SyncStats(
        filesSynced: 10,
        filesAdded: 5,
        filesModified: 3,
        filesDeleted: 2,
        bytesTransferred: 1024
    );

    $array = $stats->toArray();

    expect($array)->toBe([
        'files_synced' => 10,
        'files_added' => 5,
        'files_modified' => 3,
        'files_deleted' => 2,
        'bytes_transferred' => 1024,
    ]);
});
