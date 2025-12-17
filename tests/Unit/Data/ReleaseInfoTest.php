<?php

use Shaf\LaravelDeployer\Data\ReleaseInfo;

// =============================================================================
// Constructor Tests
// =============================================================================

test('ReleaseInfo can be created with valid YYYYMM.N format', function () {
    $release = new ReleaseInfo(
        name: '202501.1',
        createdAt: new DateTimeImmutable('2025-01-15 10:30:00'),
        user: 'deployer',
        branch: 'main'
    );

    expect($release->name)->toBe('202501.1');
    expect($release->user)->toBe('deployer');
    expect($release->branch)->toBe('main');
    expect($release->createdAt->format('Y-m-d'))->toBe('2025-01-15');
});

test('ReleaseInfo validates name format on construction', function () {
    expect(fn () => new ReleaseInfo(
        name: 'invalid',
        createdAt: new DateTimeImmutable,
        user: 'deployer',
        branch: 'main'
    ))->toThrow(InvalidArgumentException::class);
});

test('ReleaseInfo rejects old 14-digit timestamp format', function () {
    expect(fn () => new ReleaseInfo(
        name: '20250115103045',
        createdAt: new DateTimeImmutable,
        user: 'deployer',
        branch: 'main'
    ))->toThrow(InvalidArgumentException::class);
});

test('ReleaseInfo rejects empty name', function () {
    expect(fn () => new ReleaseInfo(
        name: '',
        createdAt: new DateTimeImmutable,
        user: 'deployer',
        branch: 'main'
    ))->toThrow(InvalidArgumentException::class);
});

// =============================================================================
// Valid Name Format Tests
// =============================================================================

test('ReleaseInfo accepts various valid YYYYMM.N formats', function () {
    // Single digit sequence
    $r1 = new ReleaseInfo('202501.1', new DateTimeImmutable, 'user', 'main');
    expect($r1->name)->toBe('202501.1');

    // Multi-digit sequence
    $r2 = new ReleaseInfo('202501.15', new DateTimeImmutable, 'user', 'main');
    expect($r2->name)->toBe('202501.15');

    // Different months
    $r3 = new ReleaseInfo('202512.99', new DateTimeImmutable, 'user', 'main');
    expect($r3->name)->toBe('202512.99');
});

// =============================================================================
// Static Factory Methods
// =============================================================================

test('create factory method generates ReleaseInfo with current time', function () {
    $before = new DateTimeImmutable;
    $release = ReleaseInfo::create('202501.1', 'deployer', 'main');
    $after = new DateTimeImmutable;

    expect($release->name)->toBe('202501.1');
    expect($release->user)->toBe('deployer');
    expect($release->branch)->toBe('main');
    expect($release->createdAt->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp());
    expect($release->createdAt->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
});

test('fromLogEntry reconstructs ReleaseInfo from log array', function () {
    $entry = [
        'release_name' => '202501.5',
        'created_at' => '2025-01-15T10:30:00+00:00',
        'user' => 'john',
        'target' => 'production',
    ];

    $release = ReleaseInfo::fromLogEntry($entry);

    expect($release->name)->toBe('202501.5');
    expect($release->user)->toBe('john');
    expect($release->branch)->toBe('production');
    expect($release->createdAt->format('Y-m-d H:i:s'))->toBe('2025-01-15 10:30:00');
});

test('fromLogEntry uses unknown for missing target', function () {
    $entry = [
        'release_name' => '202501.1',
        'created_at' => '2025-01-15T10:30:00+00:00',
        'user' => 'john',
    ];

    $release = ReleaseInfo::fromLogEntry($entry);

    expect($release->branch)->toBe('unknown');
});

// =============================================================================
// toLogEntry() Tests
// =============================================================================

test('toLogEntry returns array with correct structure', function () {
    $createdAt = new DateTimeImmutable('2025-01-15T10:30:00+00:00');
    $release = new ReleaseInfo('202501.3', $createdAt, 'deployer', 'staging');

    $entry = $release->toLogEntry();

    expect($entry)->toBeArray();
    expect($entry)->toHaveKeys(['created_at', 'release_name', 'user', 'target']);
    expect($entry['release_name'])->toBe('202501.3');
    expect($entry['user'])->toBe('deployer');
    expect($entry['target'])->toBe('staging');
    expect($entry['created_at'])->toBe('2025-01-15T10:30:00+00:00');
});

test('toLogEntry and fromLogEntry are reversible', function () {
    $original = ReleaseInfo::create('202501.7', 'admin', 'main');
    $entry = $original->toLogEntry();
    $restored = ReleaseInfo::fromLogEntry($entry);

    expect($restored->name)->toBe($original->name);
    expect($restored->user)->toBe($original->user);
    expect($restored->branch)->toBe($original->branch);
});

// =============================================================================
// getYearMonth() Tests
// =============================================================================

test('getYearMonth extracts year and month from name', function () {
    $release = new ReleaseInfo('202501.1', new DateTimeImmutable, 'user', 'main');

    expect($release->getYearMonth())->toBe('202501');
});

test('getYearMonth works with different months', function () {
    $jan = new ReleaseInfo('202501.1', new DateTimeImmutable, 'user', 'main');
    $dec = new ReleaseInfo('202512.15', new DateTimeImmutable, 'user', 'main');

    expect($jan->getYearMonth())->toBe('202501');
    expect($dec->getYearMonth())->toBe('202512');
});

// =============================================================================
// getSequenceNumber() Tests
// =============================================================================

test('getSequenceNumber extracts sequence from name', function () {
    $release = new ReleaseInfo('202501.1', new DateTimeImmutable, 'user', 'main');

    expect($release->getSequenceNumber())->toBe(1);
});

test('getSequenceNumber handles multi-digit sequences', function () {
    $r1 = new ReleaseInfo('202501.15', new DateTimeImmutable, 'user', 'main');
    $r2 = new ReleaseInfo('202501.100', new DateTimeImmutable, 'user', 'main');

    expect($r1->getSequenceNumber())->toBe(15);
    expect($r2->getSequenceNumber())->toBe(100);
});

// =============================================================================
// Readonly Property Tests
// =============================================================================

test('ReleaseInfo is readonly', function () {
    $release = new ReleaseInfo('202501.1', new DateTimeImmutable, 'user', 'main');

    $reflection = new ReflectionClass($release);
    expect($reflection->isReadOnly())->toBeTrue();
});
