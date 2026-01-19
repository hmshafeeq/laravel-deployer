<?php

use Shaf\LaravelDeployer\Services\GitignoreParser;

beforeEach(function () {
    $this->parser = new GitignoreParser;
    $this->tempDir = sys_get_temp_dir().'/gitignore-test-'.uniqid();
    mkdir($this->tempDir);
});

afterEach(function () {
    // Clean up temp files (including hidden files like .gitignore)
    $files = array_diff(scandir($this->tempDir), ['.', '..']);
    foreach ($files as $file) {
        unlink($this->tempDir.'/'.$file);
    }
    rmdir($this->tempDir);
});

test('returns empty array when file does not exist', function () {
    $result = $this->parser->parse('/nonexistent/.gitignore');

    expect($result)->toBeArray()->toBeEmpty();
});

test('parses simple patterns', function () {
    $content = <<<'GITIGNORE'
*.log
node_modules/
.env
GITIGNORE;

    file_put_contents($this->tempDir.'/.gitignore', $content);

    $result = $this->parser->parse($this->tempDir.'/.gitignore');

    expect($result)->toBe(['*.log', 'node_modules/', '.env']);
});

test('skips empty lines', function () {
    $content = <<<'GITIGNORE'
*.log

node_modules/

.env
GITIGNORE;

    file_put_contents($this->tempDir.'/.gitignore', $content);

    $result = $this->parser->parse($this->tempDir.'/.gitignore');

    expect($result)->toBe(['*.log', 'node_modules/', '.env']);
});

test('skips comment lines', function () {
    $content = <<<'GITIGNORE'
# This is a comment
*.log
# Another comment
node_modules/
GITIGNORE;

    file_put_contents($this->tempDir.'/.gitignore', $content);

    $result = $this->parser->parse($this->tempDir.'/.gitignore');

    expect($result)->toBe(['*.log', 'node_modules/']);
});

test('skips negation patterns', function () {
    $content = <<<'GITIGNORE'
*.log
!important.log
node_modules/
GITIGNORE;

    file_put_contents($this->tempDir.'/.gitignore', $content);

    $result = $this->parser->parse($this->tempDir.'/.gitignore');

    expect($result)->toBe(['*.log', 'node_modules/']);
});

test('handles escaped hash at beginning', function () {
    $content = <<<'GITIGNORE'
\#not-a-comment
*.log
GITIGNORE;

    file_put_contents($this->tempDir.'/.gitignore', $content);

    $result = $this->parser->parse($this->tempDir.'/.gitignore');

    expect($result)->toBe(['#not-a-comment', '*.log']);
});

test('preserves root-relative patterns', function () {
    $content = <<<'GITIGNORE'
/build
/vendor
*.log
GITIGNORE;

    file_put_contents($this->tempDir.'/.gitignore', $content);

    $result = $this->parser->parse($this->tempDir.'/.gitignore');

    expect($result)->toBe(['/build', '/vendor', '*.log']);
});

test('preserves glob patterns', function () {
    $content = <<<'GITIGNORE'
**/*.log
*.min.js
temp-*
GITIGNORE;

    file_put_contents($this->tempDir.'/.gitignore', $content);

    $result = $this->parser->parse($this->tempDir.'/.gitignore');

    expect($result)->toBe(['**/*.log', '*.min.js', 'temp-*']);
});

test('trims whitespace from patterns', function () {
    $content = "  *.log  \n  node_modules/  \n";

    file_put_contents($this->tempDir.'/.gitignore', $content);

    $result = $this->parser->parse($this->tempDir.'/.gitignore');

    expect($result)->toBe(['*.log', 'node_modules/']);
});

test('handles complex real-world gitignore', function () {
    $content = <<<'GITIGNORE'
# Build artifacts
/public/build/
/public/hot

# Dependencies
node_modules/
/vendor/

# IDE
.idea/
.vscode/
*.swp

# Environment
.env
.env.*
!.env.example

# Logs
*.log
storage/logs/

# MCP artifacts
.playwright-mcp/
.videos/
GITIGNORE;

    file_put_contents($this->tempDir.'/.gitignore', $content);

    $result = $this->parser->parse($this->tempDir.'/.gitignore');

    expect($result)->toBe([
        '/public/build/',
        '/public/hot',
        'node_modules/',
        '/vendor/',
        '.idea/',
        '.vscode/',
        '*.swp',
        '.env',
        '.env.*',
        // !.env.example is skipped (negation)
        '*.log',
        'storage/logs/',
        '.playwright-mcp/',
        '.videos/',
    ]);
});
