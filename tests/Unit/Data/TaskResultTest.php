<?php

use Shaf\LaravelDeployer\Data\TaskResult;
use Shaf\LaravelDeployer\Enums\TaskStatus;

// =============================================================================
// Constructor Tests
// =============================================================================

test('TaskResult can be created with all parameters', function () {
    $result = new TaskResult(
        name: 'deploy:sync',
        status: TaskStatus::COMPLETED,
        output: 'Files synced successfully',
        error: null,
        duration: 15.5
    );

    expect($result->name)->toBe('deploy:sync');
    expect($result->status)->toBe(TaskStatus::COMPLETED);
    expect($result->output)->toBe('Files synced successfully');
    expect($result->error)->toBeNull();
    expect($result->duration)->toBe(15.5);
});

test('TaskResult uses default values', function () {
    $result = new TaskResult(
        name: 'task',
        status: TaskStatus::PENDING
    );

    expect($result->output)->toBe('');
    expect($result->error)->toBeNull();
    expect($result->duration)->toBe(0.0);
});

// =============================================================================
// Static Factory: success()
// =============================================================================

test('success factory creates completed TaskResult', function () {
    $result = TaskResult::success('deploy:migrate');

    expect($result->name)->toBe('deploy:migrate');
    expect($result->status)->toBe(TaskStatus::COMPLETED);
    expect($result->output)->toBe('');
    expect($result->error)->toBeNull();
    expect($result->duration)->toBe(0.0);
});

test('success factory accepts output and duration', function () {
    $result = TaskResult::success('deploy:migrate', 'Migrated 5 tables', 3.2);

    expect($result->name)->toBe('deploy:migrate');
    expect($result->status)->toBe(TaskStatus::COMPLETED);
    expect($result->output)->toBe('Migrated 5 tables');
    expect($result->duration)->toBe(3.2);
});

// =============================================================================
// Static Factory: failure()
// =============================================================================

test('failure factory creates failed TaskResult', function () {
    $result = TaskResult::failure('deploy:sync', 'Connection refused');

    expect($result->name)->toBe('deploy:sync');
    expect($result->status)->toBe(TaskStatus::FAILED);
    expect($result->error)->toBe('Connection refused');
    expect($result->output)->toBe('');
});

test('failure factory accepts duration', function () {
    $result = TaskResult::failure('deploy:sync', 'Timeout', 30.0);

    expect($result->duration)->toBe(30.0);
});

// =============================================================================
// Static Factory: skipped()
// =============================================================================

test('skipped factory creates skipped TaskResult', function () {
    $result = TaskResult::skipped('deploy:assets');

    expect($result->name)->toBe('deploy:assets');
    expect($result->status)->toBe(TaskStatus::SKIPPED);
    expect($result->output)->toBe('');
    expect($result->error)->toBeNull();
    expect($result->duration)->toBe(0.0);
});

// =============================================================================
// isSuccessful() Tests
// =============================================================================

test('isSuccessful returns true for completed status', function () {
    $result = TaskResult::success('task');

    expect($result->isSuccessful())->toBeTrue();
});

test('isSuccessful returns false for failed status', function () {
    $result = TaskResult::failure('task', 'error');

    expect($result->isSuccessful())->toBeFalse();
});

test('isSuccessful returns false for skipped status', function () {
    $result = TaskResult::skipped('task');

    expect($result->isSuccessful())->toBeFalse();
});

test('isSuccessful returns false for pending status', function () {
    $result = new TaskResult('task', TaskStatus::PENDING);

    expect($result->isSuccessful())->toBeFalse();
});

test('isSuccessful returns false for running status', function () {
    $result = new TaskResult('task', TaskStatus::RUNNING);

    expect($result->isSuccessful())->toBeFalse();
});

// =============================================================================
// isFailed() Tests
// =============================================================================

test('isFailed returns true for failed status', function () {
    $result = TaskResult::failure('task', 'error');

    expect($result->isFailed())->toBeTrue();
});

test('isFailed returns false for completed status', function () {
    $result = TaskResult::success('task');

    expect($result->isFailed())->toBeFalse();
});

test('isFailed returns false for skipped status', function () {
    $result = TaskResult::skipped('task');

    expect($result->isFailed())->toBeFalse();
});

test('isFailed returns false for pending status', function () {
    $result = new TaskResult('task', TaskStatus::PENDING);

    expect($result->isFailed())->toBeFalse();
});

// =============================================================================
// Readonly Property Tests
// =============================================================================

test('TaskResult is readonly', function () {
    $result = TaskResult::success('task');

    $reflection = new ReflectionClass($result);
    expect($reflection->isReadOnly())->toBeTrue();
});
