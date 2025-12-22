<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer;

abstract class Action
{
    protected Deployer $deployer;

    /**
     * Execute the action
     *
     * @return mixed
     */
    abstract public function execute();

    /**
     * Static factory method for fluent execution
     *
     * @param  mixed  ...$args
     * @return mixed
     */
    public static function run(...$args)
    {
        $instance = new static(...$args);

        return $instance->execute();
    }

    /**
     * Write a line to output
     */
    protected function writeln(string $message, string $style = 'info'): void
    {
        $this->deployer->writeln($message, $style);
    }

    /**
     * Run a command on the remote server
     *
     * @param  string  $command  Command to run
     * @return string Command output
     */
    protected function cmd(string $command): string
    {
        return $this->deployer->run($command);
    }

    /**
     * Run a command quietly without logging
     *
     * This method executes a command without writing the command itself
     * to the output, reducing verbosity for routine operations.
     *
     * @param  string  $command  Command to run
     * @param  bool  $logResult  Whether to log the result (default: false)
     * @return string Command output
     */
    protected function runQuietly(string $command, bool $logResult = false): string
    {
        $result = $this->deployer->run($command);

        if ($logResult && ! empty(trim($result))) {
            $this->writeln($result, 'plain');
        }

        return $result;
    }

    /**
     * Run multiple commands in batch without logging each command
     *
     * Useful for setup operations where logging every mkdir/chmod
     * clutters the output.
     *
     * @param  array<string>  $commands  Array of commands to run
     * @param  string|null  $label  Optional label to display
     * @return array<string> Array of command outputs
     */
    protected function runBatch(array $commands, ?string $label = null): array
    {
        if ($label) {
            $this->writeln($label);
        }

        $results = [];
        foreach ($commands as $command) {
            $results[] = $this->runQuietly($command);
        }

        return $results;
    }

    /**
     * Run a command conditionally if path doesn't exist
     *
     * Common pattern for creating directories or files only if needed.
     *
     * @param  string  $path  Path to check
     * @param  string  $command  Command to run if path doesn't exist
     * @return string Command output or empty string if path exists
     */
    protected function runIfNotExists(string $path, string $command): string
    {
        $checkCommand = "[ -e {$path} ] || ({$command})";

        return $this->runQuietly($checkCommand);
    }

    /**
     * Test a condition on the remote server
     *
     * @param  string  $condition  Shell test condition (e.g., "-d /path" or "-f /file")
     * @return bool True if condition passes
     */
    protected function test(string $condition): bool
    {
        $result = $this->runQuietly("[ {$condition} ] && echo 'true' || echo 'false'");

        return trim($result) === 'true';
    }

    /**
     * Get the deployment path
     */
    protected function getDeployPath(): string
    {
        return $this->deployer->getDeployPath();
    }

    /**
     * Get the current release path
     */
    protected function getReleasePath(): string
    {
        return $this->deployer->getReleasePath();
    }

    /**
     * Get the shared path
     */
    protected function getSharedPath(): string
    {
        return $this->deployer->getSharedPath();
    }

    /**
     * Get the current symlink path
     */
    protected function getCurrentPath(): string
    {
        return $this->deployer->getCurrentPath();
    }
}
