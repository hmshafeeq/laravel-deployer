<?php

use Shaf\LaravelDeployer\Exceptions\ConfigurationException;
use Shaf\LaravelDeployer\Services\ConfigService;
use Symfony\Component\Console\Output\NullOutput;

test('parseJson() parses valid JSON and returns array', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'deploy_test');
    $jsonContent = '{"test": "value", "number": 42}';
    file_put_contents($tempFile, $jsonContent);

    $service = new ConfigService('/tmp', new NullOutput);
    $result = invokePrivateMethod($service, 'parseJson', [$tempFile]);

    expect($result)->toBe([
        'test' => 'value',
        'number' => 42,
    ]);

    unlink($tempFile);
});

test('parseJson() throws ConfigurationException on invalid JSON', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'deploy_test');
    $invalidJson = '{"test": "value", invalid}';
    file_put_contents($tempFile, $invalidJson);

    $service = new ConfigService('/tmp', new NullOutput);

    expect(fn () => invokePrivateMethod($service, 'parseJson', [$tempFile]))
        ->toThrow(ConfigurationException::class, 'Failed to parse JSON configuration');

    unlink($tempFile);
});

dataset('deepMergeTestData', [
    'simple override' => [
        'base' => ['a' => 1, 'b' => 2],
        'override' => ['b' => 3, 'c' => 4],
        'expected' => ['a' => 1, 'b' => 3, 'c' => 4],
    ],
    'nested merge' => [
        'base' => ['config' => ['host' => 'localhost', 'port' => 3306]],
        'override' => ['config' => ['port' => 3307, 'user' => 'deploy']],
        'expected' => ['config' => ['host' => 'localhost', 'port' => 3307, 'user' => 'deploy']],
    ],
    'skip environments key' => [
        'base' => ['environments' => ['prod' => []], 'other' => 'value'],
        'override' => ['environments' => ['dev' => []], 'other' => 'override'],
        'expected' => ['environments' => ['prod' => []], 'other' => 'override'],
    ],
]);

test('deepMerge() merges nested arrays correctly (override values)', function ($base, $override, $expected) {
    $service = new ConfigService('/tmp', new NullOutput);
    $result = invokePrivateMethod($service, 'deepMerge', [$base, $override]);

    expect($result)->toBe($expected);
})->with('deepMergeTestData');

dataset('indexedArrayReplacementData', [
    'replace indexed array' => [
        'base' => ['commands' => ['cmd1', 'cmd2']],
        'override' => ['commands' => ['new_cmd']],
        'expected' => ['commands' => ['new_cmd']],
    ],
    'merge associative array' => [
        'base' => ['config' => ['key1' => 'value1']],
        'override' => ['config' => ['key2' => 'value2']],
        'expected' => ['config' => ['key1' => 'value1', 'key2' => 'value2']],
    ],
]);

test('deepMerge() replaces indexed arrays instead of merging', function ($base, $override, $expected) {
    $service = new ConfigService('/tmp', new NullOutput);
    $result = invokePrivateMethod($service, 'deepMerge', [$base, $override]);

    expect($result)->toBe($expected);
})->with('indexedArrayReplacementData');

dataset('isIndexedArrayData', [
    'sequential numeric keys' => [[0 => 'a', 1 => 'b', 2 => 'c'], true],
    'non-sequential numeric keys' => [[0 => 'a', 2 => 'b'], false],
    'associative array' => [['key1' => 'value1', 'key2' => 'value2'], false],
    'mixed keys' => [[0 => 'a', 'key' => 'b'], false],
    'empty array' => [[], true],
    'single element' => [[0 => 'value'], true],
]);

test('isIndexedArray() returns true for sequential numeric keys', function ($array, $expected) {
    $service = new ConfigService('/tmp', new NullOutput);
    $result = invokePrivateMethod($service, 'isIndexedArray', [$array]);

    expect($result)->toBe($expected);
})->with('isIndexedArrayData');

test('resolveEnvironmentInheritance() follows single inheritance', function () {
    $config = [
        'environments' => [
            'base' => ['host' => 'localhost', 'port' => 3306],
            'prod' => ['extends' => 'base', 'port' => 3307],
        ],
    ];

    $service = new ConfigService('/tmp', new NullOutput);
    $result = invokePrivateMethod($service, 'resolveEnvironmentInheritance', ['prod', $config]);

    expect($result)->toBe(['host' => 'localhost', 'port' => 3307]);
});

test('resolveEnvironmentInheritance() follows multi-level inheritance', function () {
    $config = [
        'environments' => [
            'base' => ['host' => 'localhost', 'port' => 3306],
            'staging' => ['extends' => 'base', 'host' => 'staging.example.com'],
            'prod' => ['extends' => 'staging', 'port' => 3307],
        ],
    ];

    $service = new ConfigService('/tmp', new NullOutput);
    $result = invokePrivateMethod($service, 'resolveEnvironmentInheritance', ['prod', $config]);

    expect($result)->toBe(['host' => 'staging.example.com', 'port' => 3307]);
});

test('resolveEnvironmentInheritance() throws on circular dependency', function () {
    $config = [
        'environments' => [
            'env1' => ['extends' => 'env2'],
            'env2' => ['extends' => 'env1'],
        ],
    ];

    $service = new ConfigService('/tmp', new NullOutput);

    expect(fn () => invokePrivateMethod($service, 'resolveEnvironmentInheritance', ['env1', $config]))
        ->toThrow(ConfigurationException::class, 'Circular environment inheritance detected: env1 → env2 → env1');
});

test('resolveEnvironmentInheritance() throws on missing parent environment', function () {
    $config = [
        'environments' => [
            'child' => ['extends' => 'missing_parent'],
        ],
    ];

    $service = new ConfigService('/tmp', new NullOutput);

    expect(fn () => invokePrivateMethod($service, 'resolveEnvironmentInheritance', ['child', $config]))
        ->toThrow(ConfigurationException::class, "Environment 'child' extends 'missing_parent', but 'missing_parent' does not exist in deploy.json");
});
