<?php

namespace Shaf\LaravelDeployer\Contracts;

interface CommandExecutor
{
    /**
     * Execute a command and return the output
     */
    public function execute(string $command): string;

    /**
     * Test a condition (returns true if successful, false otherwise)
     */
    public function test(string $condition): bool;

    /**
     * Check if this is a local executor
     */
    public function isLocal(): bool;

    /**
     * Get the working directory
     */
    public function getWorkingDirectory(): string;
}
