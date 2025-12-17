<?php

use Shaf\LaravelDeployer\Enums\Environment;

// =============================================================================
// Enum Values Tests
// =============================================================================

test('Environment has correct case values', function () {
    expect(Environment::LOCAL->value)->toBe('local');
    expect(Environment::STAGING->value)->toBe('staging');
    expect(Environment::PRODUCTION->value)->toBe('production');
});

test('Environment has exactly 3 cases', function () {
    expect(Environment::cases())->toHaveCount(3);
});

// =============================================================================
// isProduction() Tests
// =============================================================================

test('isProduction returns true only for PRODUCTION', function () {
    expect(Environment::PRODUCTION->isProduction())->toBeTrue();
    expect(Environment::STAGING->isProduction())->toBeFalse();
    expect(Environment::LOCAL->isProduction())->toBeFalse();
});

// =============================================================================
// isLocal() Tests
// =============================================================================

test('isLocal returns true only for LOCAL', function () {
    expect(Environment::LOCAL->isLocal())->toBeTrue();
    expect(Environment::STAGING->isLocal())->toBeFalse();
    expect(Environment::PRODUCTION->isLocal())->toBeFalse();
});

// =============================================================================
// fromString() Tests
// =============================================================================

test('fromString parses exact environment names', function () {
    expect(Environment::fromString('local'))->toBe(Environment::LOCAL);
    expect(Environment::fromString('staging'))->toBe(Environment::STAGING);
    expect(Environment::fromString('production'))->toBe(Environment::PRODUCTION);
});

test('fromString handles case insensitivity', function () {
    expect(Environment::fromString('LOCAL'))->toBe(Environment::LOCAL);
    expect(Environment::fromString('STAGING'))->toBe(Environment::STAGING);
    expect(Environment::fromString('PRODUCTION'))->toBe(Environment::PRODUCTION);
    expect(Environment::fromString('Production'))->toBe(Environment::PRODUCTION);
});

test('fromString handles whitespace', function () {
    expect(Environment::fromString(' local '))->toBe(Environment::LOCAL);
    expect(Environment::fromString('  staging  '))->toBe(Environment::STAGING);
});

test('fromString handles prod alias', function () {
    expect(Environment::fromString('prod'))->toBe(Environment::PRODUCTION);
    expect(Environment::fromString('PROD'))->toBe(Environment::PRODUCTION);
});

test('fromString handles staging aliases', function () {
    expect(Environment::fromString('stage'))->toBe(Environment::STAGING);
    expect(Environment::fromString('stg'))->toBe(Environment::STAGING);
    expect(Environment::fromString('STG'))->toBe(Environment::STAGING);
});

test('fromString handles local aliases', function () {
    expect(Environment::fromString('dev'))->toBe(Environment::LOCAL);
    expect(Environment::fromString('development'))->toBe(Environment::LOCAL);
    expect(Environment::fromString('DEV'))->toBe(Environment::LOCAL);
});

test('fromString throws for invalid environment', function () {
    expect(fn () => Environment::fromString('invalid'))
        ->toThrow(InvalidArgumentException::class, 'Invalid environment: invalid');

    expect(fn () => Environment::fromString(''))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => Environment::fromString('test'))
        ->toThrow(InvalidArgumentException::class);
});

// =============================================================================
// getLabel() Tests
// =============================================================================

test('getLabel returns human-readable labels', function () {
    expect(Environment::LOCAL->getLabel())->toBe('Local');
    expect(Environment::STAGING->getLabel())->toBe('Staging');
    expect(Environment::PRODUCTION->getLabel())->toBe('Production');
});

// =============================================================================
// tryFrom() Tests (Built-in)
// =============================================================================

test('tryFrom returns null for invalid value', function () {
    expect(Environment::tryFrom('invalid'))->toBeNull();
    expect(Environment::tryFrom('prod'))->toBeNull(); // Alias not supported by tryFrom
});

test('tryFrom returns enum for valid value', function () {
    expect(Environment::tryFrom('local'))->toBe(Environment::LOCAL);
    expect(Environment::tryFrom('staging'))->toBe(Environment::STAGING);
    expect(Environment::tryFrom('production'))->toBe(Environment::PRODUCTION);
});
