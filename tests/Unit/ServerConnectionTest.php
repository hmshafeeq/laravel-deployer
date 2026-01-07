<?php

use Shaf\LaravelDeployer\Data\ServerConnection;

test('getConnectionString() with user returns user@hostname', function () {
    $connection = new ServerConnection('example.com', 'deploy');
    expect($connection->getConnectionString())->toBe('deploy@example.com');
});

test('getConnectionString() with custom port includes -p flag', function () {
    $connection = new ServerConnection('example.com', 'deploy', 2222);
    expect($connection->getConnectionString())->toBe('deploy@example.com -p 2222');
});

test('getConnectionString() with identity file includes -i flag', function () {
    $connection = new ServerConnection('example.com', 'deploy', null, true, true, '/path/to/key');
    expect($connection->getConnectionString())->toBe('deploy@example.com -i /path/to/key');
});

test('getConnectionString() with port and identity file includes both flags', function () {
    $connection = new ServerConnection('example.com', 'deploy', 2222, true, true, '/path/to/key');
    expect($connection->getConnectionString())->toBe('deploy@example.com -p 2222 -i /path/to/key');
});
