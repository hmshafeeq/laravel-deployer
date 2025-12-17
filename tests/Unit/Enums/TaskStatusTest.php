<?php

use Shaf\LaravelDeployer\Enums\TaskStatus;

// =============================================================================
// Enum Values Tests
// =============================================================================

test('TaskStatus has correct case values', function () {
    expect(TaskStatus::PENDING->value)->toBe('pending');
    expect(TaskStatus::RUNNING->value)->toBe('running');
    expect(TaskStatus::COMPLETED->value)->toBe('completed');
    expect(TaskStatus::FAILED->value)->toBe('failed');
    expect(TaskStatus::SKIPPED->value)->toBe('skipped');
});

test('TaskStatus has exactly 5 cases', function () {
    expect(TaskStatus::cases())->toHaveCount(5);
});

// =============================================================================
// isTerminal() Tests
// =============================================================================

test('isTerminal returns true for terminal states', function () {
    expect(TaskStatus::COMPLETED->isTerminal())->toBeTrue();
    expect(TaskStatus::FAILED->isTerminal())->toBeTrue();
    expect(TaskStatus::SKIPPED->isTerminal())->toBeTrue();
});

test('isTerminal returns false for non-terminal states', function () {
    expect(TaskStatus::PENDING->isTerminal())->toBeFalse();
    expect(TaskStatus::RUNNING->isTerminal())->toBeFalse();
});

// =============================================================================
// isSuccessful() Tests
// =============================================================================

test('isSuccessful returns true only for COMPLETED', function () {
    expect(TaskStatus::COMPLETED->isSuccessful())->toBeTrue();
});

test('isSuccessful returns false for all other states', function () {
    expect(TaskStatus::PENDING->isSuccessful())->toBeFalse();
    expect(TaskStatus::RUNNING->isSuccessful())->toBeFalse();
    expect(TaskStatus::FAILED->isSuccessful())->toBeFalse();
    expect(TaskStatus::SKIPPED->isSuccessful())->toBeFalse();
});

// =============================================================================
// tryFrom() Tests (Built-in)
// =============================================================================

test('tryFrom returns null for invalid value', function () {
    expect(TaskStatus::tryFrom('invalid'))->toBeNull();
    expect(TaskStatus::tryFrom('success'))->toBeNull();
    expect(TaskStatus::tryFrom(''))->toBeNull();
});

test('tryFrom returns enum for valid value', function () {
    expect(TaskStatus::tryFrom('pending'))->toBe(TaskStatus::PENDING);
    expect(TaskStatus::tryFrom('running'))->toBe(TaskStatus::RUNNING);
    expect(TaskStatus::tryFrom('completed'))->toBe(TaskStatus::COMPLETED);
    expect(TaskStatus::tryFrom('failed'))->toBe(TaskStatus::FAILED);
    expect(TaskStatus::tryFrom('skipped'))->toBe(TaskStatus::SKIPPED);
});

// =============================================================================
// State Transition Logic Tests
// =============================================================================

test('workflow states follow expected transitions', function () {
    // A task should start as PENDING
    $initial = TaskStatus::PENDING;
    expect($initial->isTerminal())->toBeFalse();

    // Then move to RUNNING
    $running = TaskStatus::RUNNING;
    expect($running->isTerminal())->toBeFalse();

    // Finally end in a terminal state
    $completed = TaskStatus::COMPLETED;
    $failed = TaskStatus::FAILED;
    $skipped = TaskStatus::SKIPPED;

    expect($completed->isTerminal())->toBeTrue();
    expect($failed->isTerminal())->toBeTrue();
    expect($skipped->isTerminal())->toBeTrue();
});

test('only COMPLETED is considered successful', function () {
    $terminalStates = [
        TaskStatus::COMPLETED,
        TaskStatus::FAILED,
        TaskStatus::SKIPPED,
    ];

    $successfulCount = 0;
    foreach ($terminalStates as $state) {
        if ($state->isSuccessful()) {
            $successfulCount++;
        }
    }

    expect($successfulCount)->toBe(1);
});
