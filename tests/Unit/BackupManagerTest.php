<?php

use Illuminate\Support\Facades\File;
use Shaf\LaravelDeployer\Services\BackupManager;

test('findBackup() resolves numeric ID \'1\' to first backup file', function () {
    $manager = new BackupManager('/tmp/test_backups');

    // Create test backup files
    $testDir = '/tmp/test_backups';
    if (!is_dir($testDir)) {
        mkdir($testDir, 0755, true);
    }

    $files = [
        $testDir.'/db_backup_2024_01_01_120000.sql.gz',
        $testDir.'/db_backup_2024_01_02_120000.sql.gz',
        $testDir.'/db_backup_2024_01_03_120000.sql.gz',
    ];

    foreach ($files as $file) {
        touch($file);
    }

    $result = $manager->findBackup('1');

    expect($result)->toBe($testDir.'/db_backup_2024_01_01_120000.sql.gz');

    // Cleanup
    foreach ($files as $file) {
        unlink($file);
    }
    rmdir($testDir);
});

test('findBackup() returns exact filename when provided', function () {
    $manager = new BackupManager('/tmp/test_backups');

    // Create test backup file
    $testDir = '/tmp/test_backups';
    if (!is_dir($testDir)) {
        mkdir($testDir, 0755, true);
    }

    $testFile = $testDir.'/specific_backup.sql.gz';
    touch($testFile);

    $result = $manager->findBackup('specific_backup.sql.gz');

    expect($result)->toBe($testFile);

    // Cleanup
    unlink($testFile);
    rmdir($testDir);
});

test('findBackup() returns null for non-existent backup', function () {
    $manager = new BackupManager('/tmp/empty_backups');

    // Ensure directory exists but is empty
    $testDir = '/tmp/empty_backups';
    if (!is_dir($testDir)) {
        mkdir($testDir, 0755, true);
    }

    $result = $manager->findBackup('1');

    expect($result)->toBeNull();

    // Cleanup
    rmdir($testDir);
});

test('getAvailableBackups() returns backups sorted by date desc', function () {
    $manager = new BackupManager('/tmp/test_backups_sort');

    $testDir = '/tmp/test_backups_sort';
    if (!is_dir($testDir)) {
        mkdir($testDir, 0755, true);
    }

    $files = [
        $testDir.'/db_backup_2024_01_03_120000.sql.gz', // Will be newest
        $testDir.'/db_backup_2024_01_01_120000.sql.gz', // Will be oldest
        $testDir.'/db_backup_2024_01_02_120000.sql.gz', // Will be middle
    ];

    // Create files with different timestamps
    touch($files[1], 1704133200); // Oldest
    touch($files[2], 1704216000); // Middle
    touch($files[0], 1704302400); // Newest

    $backups = $manager->getAvailableBackups();

    expect($backups)->toBeArray();
    expect($backups)->toHaveCount(3);
    // Should be sorted newest first
    expect($backups[0])->toContain('2024_01_03');
    expect($backups[1])->toContain('2024_01_02');
    expect($backups[2])->toContain('2024_01_01');

    // Cleanup
    foreach ($files as $file) {
        unlink($file);
    }
    rmdir($testDir);
});

test('getAvailableBackups() returns empty array when no backups', function () {
    $manager = new BackupManager('/tmp/empty_backups2');

    $testDir = '/tmp/empty_backups2';
    if (!is_dir($testDir)) {
        mkdir($testDir, 0755, true);
    }

    $backups = $manager->getAvailableBackups();

    expect($backups)->toBeArray();
    expect($backups)->toBeEmpty();

    // Cleanup
    rmdir($testDir);
});

test('backupsDirectoryExists() returns true when directory exists', function () {
    $testDir = '/tmp/existing_backups';
    if (!is_dir($testDir)) {
        mkdir($testDir, 0755, true);
    }

    $manager = new BackupManager($testDir);
    $result = $manager->backupsDirectoryExists();

    expect($result)->toBeTrue();

    // Cleanup
    rmdir($testDir);
});

test('backupsDirectoryExists() returns false when directory missing', function () {
    $manager = new BackupManager('/tmp/non_existent_backups_dir');
    $result = $manager->backupsDirectoryExists();

    expect($result)->toBeFalse();
});

test('getBackupMetadata() returns size and formatted date', function () {
    $testDir = '/tmp/metadata_test';
    if (!is_dir($testDir)) {
        mkdir($testDir, 0755, true);
    }

    $testFile = $testDir.'/test_backup.sql.gz';
    file_put_contents($testFile, str_repeat('x', 1024)); // 1KB file

    $manager = new BackupManager($testDir);
    $metadata = $manager->getBackupMetadata($testFile);

    expect($metadata)->toHaveKey('name');
    expect($metadata)->toHaveKey('size');
    expect($metadata)->toHaveKey('size_formatted');
    expect($metadata)->toHaveKey('date');
    expect($metadata)->toHaveKey('timestamp');

    expect($metadata['name'])->toBe('test_backup.sql.gz');
    expect($metadata['size'])->toBe(1024);
    expect($metadata['size_formatted'])->toBe('1 KB');
    expect($metadata['date'])->toBeString();
    expect($metadata['timestamp'])->toBeInt();

    // Cleanup
    unlink($testFile);
    rmdir($testDir);
});