<?php

use Shaf\LaravelDeployer\Data\DeploymentReceipt;

test('toArray() includes all required fields', function () {
    $deployedAt = new DateTimeImmutable('2023-01-01 12:00:00');
    $receipt = new DeploymentReceipt(
        release: '202501.1',
        environment: 'production',
        deployedAt: $deployedAt,
        deployedBy: 'testuser',
        durationSeconds: 45.67,
        gitCommit: 'abc123',
        gitBranch: 'main',
        gitMessage: 'Deploy production',
        filesSynced: 10,
        filesAdded: 5,
        filesModified: 3,
        filesDeleted: 2,
        bytesTransferred: 1024,
        migrationsRun: ['migration1', 'migration2'],
        postDeployCommands: ['php artisan cache:clear'],
        success: true,
        errorMessage: null
    );

    $array = $receipt->toArray();

    expect($array)->toHaveKey('release', '202501.1');
    expect($array)->toHaveKey('environment', 'production');
    expect($array)->toHaveKey('deployed_at');
    expect($array)->toHaveKey('deployed_by', 'testuser');
    expect($array)->toHaveKey('duration_seconds', 45.67);
    expect($array)->toHaveKey('git');
    expect($array['git'])->toBe([
        'commit' => 'abc123',
        'branch' => 'main',
        'message' => 'Deploy production',
    ]);
    expect($array)->toHaveKey('stats');
    expect($array['stats'])->toBe([
        'files_synced' => 10,
        'files_added' => 5,
        'files_modified' => 3,
        'files_deleted' => 2,
        'bytes_transferred' => 1024,
    ]);
    expect($array)->toHaveKey('migrations', ['migration1', 'migration2']);
    expect($array)->toHaveKey('post_deploy_commands', ['php artisan cache:clear']);
    expect($array)->toHaveKey('success', true);
    expect($array)->toHaveKey('error');
});

test('Constructor accepts DateTimeImmutable for deployedAt', function () {
    $deployedAt = new DateTimeImmutable('2023-01-01 12:00:00');
    $receipt = new DeploymentReceipt(
        release: '202501.1',
        environment: 'staging',
        deployedAt: $deployedAt,
        deployedBy: 'testuser',
        durationSeconds: 30.5
    );

    expect($receipt->deployedAt)->toBe($deployedAt);
    expect($receipt->release)->toBe('202501.1');
    expect($receipt->environment)->toBe('staging');
    expect($receipt->deployedBy)->toBe('testuser');
    expect($receipt->durationSeconds)->toBe(30.5);
});
