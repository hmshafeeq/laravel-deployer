<?php

use Shaf\LaravelDeployer\Data\SyncStrategy;

test('isGitBased() returns false for Full strategy', function () {
    expect(SyncStrategy::Full->isGitBased())->toBeFalse();
});

test('isGitBased() returns true for git-based strategies', function () {
    expect(SyncStrategy::Dirty->isGitBased())->toBeTrue();
    expect(SyncStrategy::Since->isGitBased())->toBeTrue();
    expect(SyncStrategy::Branch->isGitBased())->toBeTrue();
});

test('getLabel() returns human-readable descriptions', function () {
    expect(SyncStrategy::Full->getLabel())->toContain('rsync');
    expect(SyncStrategy::Dirty->getLabel())->toContain('uncommitted');
    expect(SyncStrategy::Since->getLabel())->toContain('commit');
    expect(SyncStrategy::Branch->getLabel())->toContain('branch');
});

test('enum values are correct strings', function () {
    expect(SyncStrategy::Full->value)->toBe('full');
    expect(SyncStrategy::Dirty->value)->toBe('dirty');
    expect(SyncStrategy::Since->value)->toBe('since');
    expect(SyncStrategy::Branch->value)->toBe('branch');
});
