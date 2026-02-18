<?php

use Shaf\LaravelDeployer\Data\SyncDiff;

test('isEmpty() returns true when all arrays empty', function () {
    $diff = new SyncDiff;
    expect($diff->isEmpty())->toBeTrue();

    $diff = new SyncDiff([], [], []);
    expect($diff->isEmpty())->toBeTrue();
});

test('isEmpty() returns false when newFiles has items', function () {
    $diff = new SyncDiff(['file1.txt'], [], []);
    expect($diff->isEmpty())->toBeFalse();
});

test('isEmpty() returns false when modifiedFiles has items', function () {
    $diff = new SyncDiff([], ['file1.txt'], []);
    expect($diff->isEmpty())->toBeFalse();
});

test('isEmpty() returns false when deletedFiles has items', function () {
    $diff = new SyncDiff([], [], ['file1.txt']);
    expect($diff->isEmpty())->toBeFalse();
});

test('hasNew/hasModified/hasDeleted() return correct booleans', function () {
    $diff = new SyncDiff(['new.txt'], ['modified.txt'], ['deleted.txt']);

    expect($diff->hasNew())->toBeTrue();
    expect($diff->hasModified())->toBeTrue();
    expect($diff->hasDeleted())->toBeTrue();

    $emptyDiff = new SyncDiff;
    expect($emptyDiff->hasNew())->toBeFalse();
    expect($emptyDiff->hasModified())->toBeFalse();
    expect($emptyDiff->hasDeleted())->toBeFalse();
});

test('newCount/modifiedCount/deletedCount() return correct counts', function () {
    $diff = new SyncDiff(
        ['file1.txt', 'file2.txt'],
        ['file3.txt'],
        ['file4.txt', 'file5.txt', 'file6.txt']
    );

    expect($diff->newCount())->toBe(2);
    expect($diff->modifiedCount())->toBe(1);
    expect($diff->deletedCount())->toBe(3);
});

test('totalCount() sums all categories correctly', function () {
    $diff = new SyncDiff(
        ['file1.txt', 'file2.txt'], // 2 new
        ['file3.txt'],              // 1 modified
        ['file4.txt', 'file5.txt']  // 2 deleted
    );

    expect($diff->totalCount())->toBe(5);

    $emptyDiff = new SyncDiff;
    expect($emptyDiff->totalCount())->toBe(0);
});

test('allFiles() returns all files across all categories', function () {
    $diff = new SyncDiff(
        ['new.txt'],
        ['modified.txt'],
        ['deleted.txt']
    );

    expect($diff->allFiles())->toBe(['new.txt', 'modified.txt', 'deleted.txt']);
});

test('allFiles() returns empty array when no files', function () {
    $diff = new SyncDiff;

    expect($diff->allFiles())->toBe([]);
});
