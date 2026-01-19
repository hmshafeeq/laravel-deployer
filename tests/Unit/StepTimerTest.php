<?php

use Shaf\LaravelDeployer\Support\StepTimer;

test('start() begins timing a named step', function () {
    $timer = new StepTimer;

    $timer->start('test-step');

    // Check that step was started by examining private property
    $reflection = new ReflectionClass(StepTimer::class);
    $stepsProperty = $reflection->getProperty('steps');
    $stepsProperty->setAccessible(true);
    $steps = $stepsProperty->getValue($timer);

    expect($steps)->toHaveKey('test-step');
    expect($steps['test-step']['start'])->toBeFloat();
    expect($steps['test-step']['end'])->toBeNull();
});

test('end() calculates correct duration for step', function () {
    $timer = new StepTimer;

    $timer->start('test-step');

    // Sleep for a short time to ensure measurable duration
    usleep(10000); // 10ms

    $timer->end('test-step');

    $duration = $timer->getDuration('test-step');
    expect($duration)->toBeFloat();
    expect($duration)->toBeGreaterThan(0.009); // At least 9ms
    expect($duration)->toBeLessThan(0.2); // Less than 200ms
});

test('getDuration() returns duration for completed step', function () {
    $timer = new StepTimer;

    $timer->start('completed-step');
    usleep(5000); // 5ms
    $timer->end('completed-step');

    $duration = $timer->getDuration('completed-step');
    expect($duration)->toBeFloat();
    expect($duration)->toBeGreaterThan(0.004);
});

test('getDuration() returns null for unknown step', function () {
    $timer = new StepTimer;

    $duration = $timer->getDuration('non-existent-step');
    expect($duration)->toBeNull();
});

test('getTimings() returns all completed step timings', function () {
    $timer = new StepTimer;

    $timer->start('step1');
    usleep(10000); // 10ms
    $timer->end('step1');

    $timer->start('step2');
    usleep(5000); // 5ms
    $timer->end('step2');

    $timings = $timer->getTimings();

    expect($timings)->toHaveKey('step1');
    expect($timings)->toHaveKey('step2');
    expect($timings['step1'])->toBeFloat();
    expect($timings['step2'])->toBeFloat();
    expect($timings['step1'])->toBeGreaterThan($timings['step2']); // step1 should be longer
});

test('endCurrent() ends the currently running step', function () {
    $timer = new StepTimer;

    $timer->start('current-step');
    usleep(10000); // 10ms
    $timer->endCurrent();

    $duration = $timer->getDuration('current-step');
    expect($duration)->toBeFloat();
    expect($duration)->toBeGreaterThan(0.009);
});

test('getFormattedTimings() formats durations as strings', function () {
    $timer = new StepTimer;

    $timer->start('milliseconds');
    usleep(10000); // 10ms - should format as milliseconds
    $timer->end('milliseconds');

    $formatted = $timer->getFormattedTimings();

    expect($formatted)->toHaveKey('milliseconds');
    expect($formatted['milliseconds'])->toBeString();
    expect($formatted['milliseconds'])->toContain('ms');
});
