<?php

use Shaf\LaravelDeployer\Data\ReleaseInfo;

test('validateName() accepts valid formats', function () {
    expect(fn () => new ReleaseInfo('202501.1', new DateTimeImmutable, 'user', 'branch'))->not->toThrow(Exception::class);
    expect(fn () => new ReleaseInfo('202501.99', new DateTimeImmutable, 'user', 'branch'))->not->toThrow(Exception::class);
    expect(fn () => new ReleaseInfo('202512.123', new DateTimeImmutable, 'user', 'branch'))->not->toThrow(Exception::class);
});

test('validateName() rejects invalid formats', function () {
    expect(fn () => new ReleaseInfo('', new DateTimeImmutable, 'user', 'branch'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ReleaseInfo('invalid', new DateTimeImmutable, 'user', 'branch'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ReleaseInfo('2025.1', new DateTimeImmutable, 'user', 'branch'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ReleaseInfo('20251.1', new DateTimeImmutable, 'user', 'branch'))->toThrow(InvalidArgumentException::class);
});

test('getYearMonth() extracts correct year-month from release name', function () {
    $release = new ReleaseInfo('202501.1', new DateTimeImmutable, 'user', 'branch');
    expect($release->getYearMonth())->toBe('202501');

    $release = new ReleaseInfo('202512.99', new DateTimeImmutable, 'user', 'branch');
    expect($release->getYearMonth())->toBe('202512');
});

test('getSequenceNumber() extracts correct sequence number', function () {
    $release = new ReleaseInfo('202501.1', new DateTimeImmutable, 'user', 'branch');
    expect($release->getSequenceNumber())->toBe(1);

    $release = new ReleaseInfo('202501.99', new DateTimeImmutable, 'user', 'branch');
    expect($release->getSequenceNumber())->toBe(99);
});

test('toLogEntry() produces correct array structure', function () {
    $createdAt = new DateTimeImmutable('2023-01-01 12:00:00');
    $release = new ReleaseInfo('202501.1', $createdAt, 'testuser', 'main');

    $logEntry = $release->toLogEntry();

    expect($logEntry)->toBe([
        'created_at' => '2023-01-01T12:00:00+00:00',
        'release_name' => '202501.1',
        'user' => 'testuser',
        'target' => 'main',
    ]);
});

test('fromLogEntry() parses array correctly', function () {
    $logEntry = [
        'created_at' => '2023-01-01T12:00:00+00:00',
        'release_name' => '202501.1',
        'user' => 'testuser',
        'target' => 'main',
    ];

    $release = ReleaseInfo::fromLogEntry($logEntry);

    expect($release->name)->toBe('202501.1');
    expect($release->createdAt->format('c'))->toBe('2023-01-01T12:00:00+00:00');
    expect($release->user)->toBe('testuser');
    expect($release->branch)->toBe('main');
});

test('create() factory method creates valid ReleaseInfo', function () {
    $release = ReleaseInfo::create('202501.1', 'testuser', 'main');

    expect($release->name)->toBe('202501.1');
    expect($release->user)->toBe('testuser');
    expect($release->branch)->toBe('main');
    expect($release->createdAt)->toBeInstanceOf(DateTimeImmutable::class);
});
