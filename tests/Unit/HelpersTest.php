<?php

require_once __DIR__.'/../../helpers/deployment.php';

test('format_duration formats milliseconds', function () {
    expect(format_duration(0.5))->toBe('500ms');
    expect(format_duration(0.1))->toBe('100ms');
    expect(format_duration(0.05))->toBe('50ms');
});

test('format_duration formats seconds', function () {
    expect(format_duration(1))->toBe('1s');
    expect(format_duration(5.5))->toBe('5.5s');
    expect(format_duration(30))->toBe('30s');
    expect(format_duration(59.9))->toBe('59.9s');
});

test('format_duration formats minutes and seconds', function () {
    expect(format_duration(60))->toBe('1m');
    expect(format_duration(90))->toBe('1m 30s');
    expect(format_duration(125))->toBe('2m 5s');
    expect(format_duration(300))->toBe('5m');
    expect(format_duration(3599))->toBe('59m 59s');
});

test('format_duration formats hours and minutes', function () {
    expect(format_duration(3600))->toBe('1h');
    expect(format_duration(3660))->toBe('1h 1m');
    expect(format_duration(7200))->toBe('2h');
    expect(format_duration(7800))->toBe('2h 10m');
});
