<?php

require_once __DIR__.'/../../helpers/deployment.php';

// =============================================================================
// format_duration() Tests
// =============================================================================

test('format_duration formats milliseconds', function () {
    expect(format_duration(0.5))->toBe('500ms');
    expect(format_duration(0.1))->toBe('100ms');
    expect(format_duration(0.05))->toBe('50ms');
    expect(format_duration(0.001))->toBe('1ms');
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

// =============================================================================
// deployment_timestamp() Tests
// =============================================================================

test('deployment_timestamp returns ISO 8601 format', function () {
    $timestamp = deployment_timestamp();

    expect($timestamp)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+0000$/');
});

test('deployment_timestamp returns current time', function () {
    $before = time();
    $timestamp = deployment_timestamp();
    $after = time();

    $parsed = strtotime($timestamp);
    expect($parsed)->toBeGreaterThanOrEqual($before);
    expect($parsed)->toBeLessThanOrEqual($after);
});

// =============================================================================
// release_name_from_timestamp() Tests
// =============================================================================

test('release_name_from_timestamp generates 14-digit name', function () {
    $name = release_name_from_timestamp();

    expect($name)->toMatch('/^\d{14}$/');
});

test('release_name_from_timestamp uses provided timestamp', function () {
    $timestamp = '2025-01-15 10:30:45';
    $name = release_name_from_timestamp($timestamp);

    expect($name)->toBe('20250115103045');
});

test('release_name_from_timestamp uses current time when null', function () {
    $before = date('YmdHis');
    $name = release_name_from_timestamp(null);
    $after = date('YmdHis');

    expect((int) $name)->toBeGreaterThanOrEqual((int) $before);
    expect((int) $name)->toBeLessThanOrEqual((int) $after);
});

// =============================================================================
// format_bytes() Tests
// =============================================================================

test('format_bytes formats bytes', function () {
    expect(format_bytes(0))->toBe('0 B');
    expect(format_bytes(100))->toBe('100 B');
    expect(format_bytes(1023))->toBe('1023 B');
    expect(format_bytes(1024))->toBe('1024 B'); // Boundary: exactly 1024 stays in B
});

test('format_bytes formats kilobytes', function () {
    expect(format_bytes(1025))->toBe('1 KB'); // Just over 1024
    expect(format_bytes(2048))->toBe('2 KB');
    expect(format_bytes(10240))->toBe('10 KB');
    expect(format_bytes(1048576))->toBe('1024 KB'); // Boundary: exactly 1MB stays in KB
});

test('format_bytes formats megabytes', function () {
    expect(format_bytes(1048577))->toBe('1 MB'); // Just over 1MB
    expect(format_bytes(5242880))->toBe('5 MB');
    expect(format_bytes(10485760))->toBe('10 MB');
});

test('format_bytes formats gigabytes', function () {
    expect(format_bytes(1073741825))->toBe('1 GB'); // Just over 1GB
    expect(format_bytes(2147483648))->toBe('2 GB');
});

test('format_bytes respects precision parameter', function () {
    expect(format_bytes(1536, 0))->toBe('2 KB');
    expect(format_bytes(1536, 1))->toBe('1.5 KB');
    expect(format_bytes(1536, 3))->toBe('1.5 KB');
});

// =============================================================================
// deployment_lock_file() Tests
// =============================================================================

test('deployment_lock_file returns correct path', function () {
    expect(deployment_lock_file('/var/www/app'))->toBe('/var/www/app/.dep/deploy.lock');
    expect(deployment_lock_file('/var/www/app/'))->toBe('/var/www/app/.dep/deploy.lock');
});

test('deployment_lock_file handles trailing slashes', function () {
    expect(deployment_lock_file('/var/www/app///'))->toBe('/var/www/app/.dep/deploy.lock');
});

// =============================================================================
// normalize_path() Tests
// =============================================================================

test('normalize_path removes trailing slashes', function () {
    expect(normalize_path('/var/www/app/'))->toBe('/var/www/app');
    expect(normalize_path('/var/www/app///'))->toBe('/var/www/app');
    expect(normalize_path('/var/www/app'))->toBe('/var/www/app');
});

test('normalize_path handles root path', function () {
    expect(normalize_path('/'))->toBe('');
});

test('normalize_path handles empty string', function () {
    expect(normalize_path(''))->toBe('');
});

// =============================================================================
// build_remote_path() Tests
// =============================================================================

test('build_remote_path joins path components', function () {
    expect(build_remote_path('var', 'www', 'app'))->toBe('var/www/app');
    expect(build_remote_path('/var/', '/www/', '/app/'))->toBe('var/www/app');
});

test('build_remote_path handles leading and trailing slashes', function () {
    expect(build_remote_path('/var/www/', '/releases/', '/202501.1/'))->toBe('var/www/releases/202501.1');
});

test('build_remote_path filters empty parts', function () {
    expect(build_remote_path('var', '', 'www', '', 'app'))->toBe('var/www/app');
});

test('build_remote_path handles single component', function () {
    expect(build_remote_path('/var/'))->toBe('var');
});

// =============================================================================
// escape_shell_arg_multiple() Tests
// =============================================================================

test('escape_shell_arg_multiple escapes all arguments', function () {
    $args = ['file.txt', 'path with spaces', "file'name"];
    $escaped = escape_shell_arg_multiple($args);

    expect($escaped)->toHaveCount(3);
    expect($escaped[0])->toBe("'file.txt'");
    expect($escaped[1])->toBe("'path with spaces'");
    expect($escaped[2])->toBe("'file'\\''name'");
});

test('escape_shell_arg_multiple handles empty array', function () {
    expect(escape_shell_arg_multiple([]))->toBe([]);
});

// =============================================================================
// is_valid_release_name() Tests
// =============================================================================

test('is_valid_release_name validates 14-digit format', function () {
    expect(is_valid_release_name('20250115103045'))->toBeTrue();
    expect(is_valid_release_name('20251231235959'))->toBeTrue();
});

test('is_valid_release_name rejects invalid formats', function () {
    expect(is_valid_release_name('202501.1'))->toBeFalse(); // YYYYMM.N format
    expect(is_valid_release_name('2025011510304'))->toBeFalse(); // 13 digits
    expect(is_valid_release_name('202501151030456'))->toBeFalse(); // 15 digits
    expect(is_valid_release_name('abcdefghijklmn'))->toBeFalse(); // letters
    expect(is_valid_release_name(''))->toBeFalse();
});

// =============================================================================
// parse_release_timestamp() Tests
// =============================================================================

test('parse_release_timestamp parses valid release name', function () {
    $timestamp = parse_release_timestamp('20250115103045');

    expect($timestamp)->toBe(strtotime('2025-01-15 10:30:45'));
});

test('parse_release_timestamp returns null for invalid format', function () {
    expect(parse_release_timestamp('invalid'))->toBeNull();
    expect(parse_release_timestamp('202501.1'))->toBeNull();
    expect(parse_release_timestamp(''))->toBeNull();
});

test('parse_release_timestamp handles edge cases', function () {
    // Midnight
    $midnight = parse_release_timestamp('20250115000000');
    expect($midnight)->toBe(strtotime('2025-01-15 00:00:00'));

    // End of day
    $endOfDay = parse_release_timestamp('20250115235959');
    expect($endOfDay)->toBe(strtotime('2025-01-15 23:59:59'));
});

// =============================================================================
// get_release_age() Tests
// =============================================================================

test('get_release_age returns Unknown for invalid release name', function () {
    expect(get_release_age('invalid'))->toBe('Unknown');
    expect(get_release_age('202501.1'))->toBe('Unknown');
});

test('get_release_age formats seconds ago', function () {
    // Create a release name for 30 seconds ago
    $timestamp = date('YmdHis', time() - 30);
    $age = get_release_age($timestamp);

    expect($age)->toMatch('/^\d+ seconds ago$/');
});

test('get_release_age formats minutes ago', function () {
    // Create a release name for 5 minutes ago
    $timestamp = date('YmdHis', time() - 300);
    $age = get_release_age($timestamp);

    expect($age)->toMatch('/^\d+ minutes ago$/');
});

test('get_release_age formats hours ago', function () {
    // Create a release name for 2 hours ago
    $timestamp = date('YmdHis', time() - 7200);
    $age = get_release_age($timestamp);

    expect($age)->toMatch('/^\d+ hours ago$/');
});

test('get_release_age formats days ago', function () {
    // Create a release name for 3 days ago
    $timestamp = date('YmdHis', time() - 259200);
    $age = get_release_age($timestamp);

    expect($age)->toMatch('/^\d+ days ago$/');
});
