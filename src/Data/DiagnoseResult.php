<?php

namespace Shaf\LaravelDeployer\Data;

/**
 * Container for all diagnostic check results.
 */
class DiagnoseResult
{
    /** @var array<string, DiagnoseCheck[]> */
    private array $checksByCategory = [];

    public function __construct(
        public string $environment,
        public string $deployPath,
        public string $deployUser,
        public string $webGroup,
    ) {}

    /**
     * Add a check to a category.
     */
    public function addCheck(string $category, DiagnoseCheck $check): void
    {
        if (! isset($this->checksByCategory[$category])) {
            $this->checksByCategory[$category] = [];
        }
        $this->checksByCategory[$category][] = $check;
    }

    /**
     * Get all checks grouped by category.
     *
     * @return array<string, DiagnoseCheck[]>
     */
    public function getChecksByCategory(): array
    {
        return $this->checksByCategory;
    }

    /**
     * Get all checks as a flat array.
     *
     * @return DiagnoseCheck[]
     */
    public function getAllChecks(): array
    {
        $all = [];
        foreach ($this->checksByCategory as $checks) {
            $all = array_merge($all, $checks);
        }

        return $all;
    }

    /**
     * Get all failed checks.
     *
     * @return DiagnoseCheck[]
     */
    public function getFailedChecks(): array
    {
        return array_filter($this->getAllChecks(), fn (DiagnoseCheck $c) => $c->failed());
    }

    /**
     * Get all checks with fixes available.
     *
     * @return DiagnoseCheck[]
     */
    public function getChecksWithFixes(): array
    {
        return array_filter($this->getAllChecks(), fn (DiagnoseCheck $c) => $c->fix !== null);
    }

    /**
     * Check if all checks passed (no failures).
     */
    public function passed(): bool
    {
        return count($this->getFailedChecks()) === 0;
    }

    /**
     * Count passed checks.
     */
    public function passedCount(): int
    {
        return count(array_filter($this->getAllChecks(), fn (DiagnoseCheck $c) => $c->passed()));
    }

    /**
     * Count failed checks.
     */
    public function failedCount(): int
    {
        return count($this->getFailedChecks());
    }

    /**
     * Count warning checks.
     */
    public function warnCount(): int
    {
        return count(array_filter($this->getAllChecks(), fn (DiagnoseCheck $c) => $c->warned()));
    }

    /**
     * Total number of checks.
     */
    public function totalCount(): int
    {
        return count($this->getAllChecks());
    }

    /**
     * Get summary string.
     */
    public function getSummary(): string
    {
        $passed = $this->passedCount();
        $total = $this->totalCount();
        $failed = $this->failedCount();
        $warns = $this->warnCount();

        if ($failed === 0 && $warns === 0) {
            return "✓ PASSED ({$passed}/{$total} checks)";
        }

        if ($failed === 0) {
            return "⚠ PASSED with warnings ({$passed}/{$total} checks, {$warns} warnings)";
        }

        return "✗ FAILED ({$failed} issues found)";
    }

    /**
     * Generate a fix script for all issues.
     */
    public function generateFixScript(): string
    {
        $lines = [
            '#!/bin/bash',
            '# Permission fix script for '.$this->environment,
            '# Deploy path: '.$this->deployPath,
            '# Generated: '.date('Y-m-d H:i:s'),
            '# Review carefully before running!',
            '',
            'set -e',
            '',
            'DEPLOY_PATH="'.$this->deployPath.'"',
            'DEPLOY_USER="'.$this->deployUser.'"',
            'WEB_GROUP="'.$this->webGroup.'"',
            '',
            'echo "Fixing permissions for $DEPLOY_PATH..."',
            '',
        ];

        $issueNum = 0;
        foreach ($this->getChecksWithFixes() as $check) {
            $issueNum++;
            $lines[] = "# Issue {$issueNum}: {$check->name}";
            $lines[] = "# {$check->message}";

            // Parse the fix command (may have multiple lines)
            $fixLines = explode("\n", trim($check->fix));
            foreach ($fixLines as $fixLine) {
                if (! empty(trim($fixLine)) && ! str_starts_with(trim($fixLine), '#')) {
                    $lines[] = $fixLine;
                }
            }
            $lines[] = 'echo "✓ Fixed: '.$check->name.'"';
            $lines[] = '';
        }

        $lines[] = 'echo ""';
        $lines[] = 'echo "Done! Reconnect SSH if group membership was changed."';

        return implode("\n", $lines);
    }
}
