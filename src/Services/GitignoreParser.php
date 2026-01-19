<?php

namespace Shaf\LaravelDeployer\Services;

/**
 * Parses .gitignore files and converts patterns to rsync-compatible excludes.
 */
class GitignoreParser
{
    public function __construct(
        private ?CommandService $cmdService = null
    ) {}

    /**
     * Parse a .gitignore file and return rsync-compatible exclude patterns.
     *
     * @return array<string>
     */
    public function parse(string $gitignorePath): array
    {
        if (! file_exists($gitignorePath)) {
            $this->verbose("No .gitignore found at: {$gitignorePath}");

            return [];
        }

        $content = file_get_contents($gitignorePath);
        $lines = explode("\n", $content);
        $patterns = [];

        foreach ($lines as $line) {
            $pattern = $this->parseLine($line);
            if ($pattern !== null) {
                $patterns[] = $pattern;
            }
        }

        $this->verbose('Parsed '.count($patterns).' patterns from .gitignore');

        return $patterns;
    }

    /**
     * Parse a single line from .gitignore.
     *
     * @return string|null The rsync exclude pattern, or null if line should be skipped
     */
    private function parseLine(string $line): ?string
    {
        // Trim whitespace
        $line = trim($line);

        // Skip empty lines
        if ($line === '') {
            return null;
        }

        // Skip comments
        if (str_starts_with($line, '#')) {
            return null;
        }

        // Skip negation patterns (rsync doesn't handle these the same way)
        if (str_starts_with($line, '!')) {
            $this->verbose("Skipping negation pattern: {$line}");

            return null;
        }

        // Handle escaped hash at the beginning
        if (str_starts_with($line, '\\#')) {
            $line = substr($line, 1);
        }

        // Gitignore and rsync patterns are largely compatible
        // - Wildcards (*) work the same
        // - Directory markers (/) work the same
        // - ** for recursive matching works the same
        return $line;
    }

    /**
     * Output verbose message.
     */
    private function verbose(string $message): void
    {
        $this->cmdService?->debug("[gitignore] {$message}");
    }
}
