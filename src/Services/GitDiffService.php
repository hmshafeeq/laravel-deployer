<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Data\SyncFileCategories;
use Shaf\LaravelDeployer\Data\SyncStrategy;
use Symfony\Component\Process\Process;

class GitDiffService
{
    public function __construct(
        private string $basePath
    ) {}

    /**
     * Get the list of changed files based on the sync strategy.
     *
     * @return array<string>
     */
    public function getChangedFiles(SyncStrategy $strategy, ?string $reference = null): array
    {
        return match ($strategy) {
            SyncStrategy::Dirty => $this->getDirtyFiles(),
            SyncStrategy::Since => $this->getFilesSinceCommit($reference ?? 'HEAD'),
            SyncStrategy::Branch => $this->getFilesSinceBranch($reference ?? 'main'),
            SyncStrategy::Full => throw new \InvalidArgumentException('Full strategy does not use git diff'),
        };
    }

    /**
     * Get uncommitted files (staged + unstaged + untracked).
     *
     * @return array<string>
     */
    public function getDirtyFiles(): array
    {
        $output = $this->runGit(['status', '--porcelain']);

        if (empty(trim($output))) {
            return [];
        }

        $lines = explode("\n", trim($output));

        return array_values(array_filter(array_map(function (string $line): ?string {
            $line = trim($line);
            if (empty($line)) {
                return null;
            }

            // Handle renamed files (R  old -> new)
            if (str_starts_with($line, 'R')) {
                $parts = explode(' -> ', substr($line, 3));

                return trim(end($parts));
            }

            return trim(substr($line, 3));
        }, $lines)));
    }

    /**
     * Get files changed since a specific commit.
     *
     * @return array<string>
     */
    public function getFilesSinceCommit(string $commit): array
    {
        $output = $this->runGit(['diff', '--name-only', "{$commit}..HEAD"]);

        return $this->parseFileList($output);
    }

    /**
     * Get files changed compared to a branch (merge-base diff).
     *
     * @return array<string>
     */
    public function getFilesSinceBranch(string $branch): array
    {
        $output = $this->runGit(['diff', '--name-only', "{$branch}...HEAD"]);

        return $this->parseFileList($output);
    }

    /**
     * Categorize files for smart step skipping.
     *
     * @param  array<string>  $files
     */
    public function categorizeFiles(array $files, SyncStrategy $strategy): SyncFileCategories
    {
        if ($strategy === SyncStrategy::Dirty) {
            $statusOutput = $this->runGit(['status', '--porcelain']);
            $lines = array_filter(explode("\n", trim($statusOutput)));

            return SyncFileCategories::fromGitStatus($lines);
        }

        return SyncFileCategories::fromFileList($files);
    }

    /**
     * Write file list to a temp file for rsync --files-from.
     */
    public function writeFilesFromList(array $files): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'deployer-files-from-');

        file_put_contents($tempFile, implode("\n", $files)."\n");

        return $tempFile;
    }

    /**
     * Validate that a commit reference exists.
     */
    public function isValidCommit(string $ref): bool
    {
        try {
            $this->runGit(['rev-parse', '--verify', $ref]);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Validate that a branch exists.
     */
    public function isValidBranch(string $branch): bool
    {
        try {
            $this->runGit(['rev-parse', '--verify', $branch]);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * @return array<string>
     */
    private function parseFileList(string $output): array
    {
        if (empty(trim($output))) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", trim($output)))
        ));
    }

    private function runGit(array $args): string
    {
        $process = new Process(['git', ...$args], $this->basePath);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                'Git command failed: git '.implode(' ', $args)."\n".$process->getErrorOutput()
            );
        }

        return $process->getOutput();
    }
}
