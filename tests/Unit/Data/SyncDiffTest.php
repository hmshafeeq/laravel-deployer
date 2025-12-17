<?php

use Shaf\LaravelDeployer\Data\SyncDiff;

// =============================================================================
// Constructor Tests
// =============================================================================

test('SyncDiff can be created with default empty arrays', function () {
    $diff = new SyncDiff;

    expect($diff->newFiles)->toBe([]);
    expect($diff->modifiedFiles)->toBe([]);
    expect($diff->deletedFiles)->toBe([]);
});

test('SyncDiff can be created with arrays', function () {
    $diff = new SyncDiff(
        newFiles: ['new1.php', 'new2.php'],
        modifiedFiles: ['mod1.php'],
        deletedFiles: ['del1.php', 'del2.php', 'del3.php']
    );

    expect($diff->newFiles)->toBe(['new1.php', 'new2.php']);
    expect($diff->modifiedFiles)->toBe(['mod1.php']);
    expect($diff->deletedFiles)->toBe(['del1.php', 'del2.php', 'del3.php']);
});

// =============================================================================
// isEmpty() Tests
// =============================================================================

test('isEmpty returns true when all arrays are empty', function () {
    $diff = new SyncDiff;

    expect($diff->isEmpty())->toBeTrue();
});

test('isEmpty returns false when newFiles is not empty', function () {
    $diff = new SyncDiff(newFiles: ['file.php']);

    expect($diff->isEmpty())->toBeFalse();
});

test('isEmpty returns false when modifiedFiles is not empty', function () {
    $diff = new SyncDiff(modifiedFiles: ['file.php']);

    expect($diff->isEmpty())->toBeFalse();
});

test('isEmpty returns false when deletedFiles is not empty', function () {
    $diff = new SyncDiff(deletedFiles: ['file.php']);

    expect($diff->isEmpty())->toBeFalse();
});

// =============================================================================
// hasNew() Tests
// =============================================================================

test('hasNew returns false when newFiles is empty', function () {
    $diff = new SyncDiff;

    expect($diff->hasNew())->toBeFalse();
});

test('hasNew returns true when newFiles has items', function () {
    $diff = new SyncDiff(newFiles: ['new.php']);

    expect($diff->hasNew())->toBeTrue();
});

// =============================================================================
// hasModified() Tests
// =============================================================================

test('hasModified returns false when modifiedFiles is empty', function () {
    $diff = new SyncDiff;

    expect($diff->hasModified())->toBeFalse();
});

test('hasModified returns true when modifiedFiles has items', function () {
    $diff = new SyncDiff(modifiedFiles: ['modified.php']);

    expect($diff->hasModified())->toBeTrue();
});

// =============================================================================
// hasDeleted() Tests
// =============================================================================

test('hasDeleted returns false when deletedFiles is empty', function () {
    $diff = new SyncDiff;

    expect($diff->hasDeleted())->toBeFalse();
});

test('hasDeleted returns true when deletedFiles has items', function () {
    $diff = new SyncDiff(deletedFiles: ['deleted.php']);

    expect($diff->hasDeleted())->toBeTrue();
});

// =============================================================================
// Count Methods Tests
// =============================================================================

test('newCount returns count of new files', function () {
    $diff = new SyncDiff(newFiles: ['a.php', 'b.php', 'c.php']);

    expect($diff->newCount())->toBe(3);
});

test('newCount returns 0 for empty array', function () {
    $diff = new SyncDiff;

    expect($diff->newCount())->toBe(0);
});

test('modifiedCount returns count of modified files', function () {
    $diff = new SyncDiff(modifiedFiles: ['a.php', 'b.php']);

    expect($diff->modifiedCount())->toBe(2);
});

test('modifiedCount returns 0 for empty array', function () {
    $diff = new SyncDiff;

    expect($diff->modifiedCount())->toBe(0);
});

test('deletedCount returns count of deleted files', function () {
    $diff = new SyncDiff(deletedFiles: ['a.php']);

    expect($diff->deletedCount())->toBe(1);
});

test('deletedCount returns 0 for empty array', function () {
    $diff = new SyncDiff;

    expect($diff->deletedCount())->toBe(0);
});

// =============================================================================
// totalCount() Tests
// =============================================================================

test('totalCount returns sum of all file counts', function () {
    $diff = new SyncDiff(
        newFiles: ['a.php', 'b.php'],
        modifiedFiles: ['c.php', 'd.php', 'e.php'],
        deletedFiles: ['f.php']
    );

    expect($diff->totalCount())->toBe(6);
});

test('totalCount returns 0 for empty diff', function () {
    $diff = new SyncDiff;

    expect($diff->totalCount())->toBe(0);
});

test('totalCount handles single category', function () {
    $diffNew = new SyncDiff(newFiles: ['a.php', 'b.php']);
    $diffMod = new SyncDiff(modifiedFiles: ['a.php']);
    $diffDel = new SyncDiff(deletedFiles: ['a.php', 'b.php', 'c.php']);

    expect($diffNew->totalCount())->toBe(2);
    expect($diffMod->totalCount())->toBe(1);
    expect($diffDel->totalCount())->toBe(3);
});

// =============================================================================
// Readonly Property Tests
// =============================================================================

test('SyncDiff is readonly', function () {
    $diff = new SyncDiff(newFiles: ['a.php']);

    $reflection = new ReflectionClass($diff);
    expect($reflection->isReadOnly())->toBeTrue();
});
