<?php

namespace Shaf\LaravelDeployer\Services;

use Symfony\Component\Process\Process;

/**
 * Service for detecting if Vite development server is running
 *
 * Checks if the Vite development server is currently running in the
 * project directory to prevent deployment conflicts.
 */
class ViteDetector
{
    /**
     * Check if Vite development server is running
     *
     * Searches for active node processes running vite from the project directory.
     *
     * @return bool True if Vite is running, false otherwise
     */
    public function isRunning(): bool
    {
        $process = Process::fromShellCommandline('ps aux');
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        $output = $process->getOutput();
        $projectPath = base_path();

        // Look for vite processes running from this project's directory
        foreach (explode("\n", $output) as $line) {
            if ($this->isViteProcess($line, $projectPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a process line represents a Vite process for this project
     *
     * @param  string  $processLine  A line from ps aux output
     * @param  string  $projectPath  The project base path
     */
    protected function isViteProcess(string $processLine, string $projectPath): bool
    {
        return str_contains($processLine, 'node_modules/.bin/vite')
            && str_contains($processLine, $projectPath);
    }
}
