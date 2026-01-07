<?php

use Shaf\LaravelDeployer\Enums\Environment;

test('fromString() with valid inputs', function () {
    expect(Environment::fromString('local'))->toBe(Environment::LOCAL);
    expect(Environment::fromString('staging'))->toBe(Environment::STAGING);
    expect(Environment::fromString('production'))->toBe(Environment::PRODUCTION);
});

test('fromString() case insensitivity', function () {
    expect(Environment::fromString('LOCAL'))->toBe(Environment::LOCAL);
    expect(Environment::fromString('Staging'))->toBe(Environment::STAGING);
    expect(Environment::fromString('PRODUCTION'))->toBe(Environment::PRODUCTION);
});

test('fromString() throws InvalidArgumentException for invalid input', function () {
    expect(fn () => Environment::fromString('invalid'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Environment::fromString(''))->toThrow(InvalidArgumentException::class);
});

test('isProduction() returns true only for production', function () {
    expect(Environment::LOCAL->isProduction())->toBeFalse();
    expect(Environment::STAGING->isProduction())->toBeFalse();
    expect(Environment::PRODUCTION->isProduction())->toBeTrue();
});

test('isLocal() returns true only for local', function () {
    expect(Environment::LOCAL->isLocal())->toBeTrue();
    expect(Environment::STAGING->isLocal())->toBeFalse();
    expect(Environment::PRODUCTION->isLocal())->toBeFalse();
});
