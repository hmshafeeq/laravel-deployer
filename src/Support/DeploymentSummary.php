<?php

namespace Shaf\LaravelDeployer\Support;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\SyncDiff;
use Shaf\LaravelDeployer\Data\SyncStats;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deployment summary dashboard.
 * Displays a formatted summary of the deployment results.
 */
class DeploymentSummary
{
    // Inner width (content area between borders)
    private const INNER_WIDTH = 58;

    public function __construct(
        private OutputInterface $output,
        private DeploymentConfig $config
    ) {}

    /**
     * Display the deployment success summary
     *
     * @param  array<string, float>  $stepTimings
     * @param  array{branch: string, commit: ?string, message: ?string, author: ?string}|null  $gitInfo
     * @param  array<array{category: string, message: string}>  $warnings
     */
    public function showSuccess(
        string $releaseName,
        float $duration,
        ?SyncDiff $syncDiff = null,
        ?SyncStats $syncStats = null,
        int $migrationsRun = 0,
        ?string $url = null,
        array $stepTimings = [],
        ?array $gitInfo = null,
        array $warnings = []
    ): void {
        $this->output->writeln('');

        $rows = [
            $this->formatRow('Environment', $this->config->environment->value),
            $this->formatRow('Release', $releaseName),
        ];

        // Add git info if available
        if ($gitInfo !== null && ! empty($gitInfo['commit'])) {
            $branch = $gitInfo['branch'];
            $commit = $gitInfo['commit'];
            $rows[] = $this->formatRow('Git', "{$branch} @ {$commit}");
        }

        $rows[] = $this->formatRow('Duration', format_duration($duration));
        $rows[] = $this->formatFilesRow($syncDiff, $syncStats);

        if ($migrationsRun > 0) {
            $rows[] = $this->formatRow('Migrations', "{$migrationsRun} executed");
        }

        if ($url) {
            $rows[] = $this->formatRow('URL', $url);
        }

        // Add warnings section if any
        if (! empty($warnings)) {
            $rows[] = '__separator__'; // Draw separator line
            $rows = array_merge($rows, $this->formatWarningsSection($warnings));
        }

        $this->drawBox('DEPLOYMENT COMPLETE', 'green', $rows);
        $this->output->writeln('');

        // Show step timings if available and verbose
        if (! empty($stepTimings) && $this->output->isVerbose()) {
            $this->showStepTimings($stepTimings);
        }
    }

    /**
     * Display the sync-only deployment success summary
     *
     * @param  array<string, float>  $stepTimings
     * @param  array{branch: string, commit: ?string, message: ?string, author: ?string}|null  $gitInfo
     * @param  array<array{category: string, message: string}>  $warnings
     */
    public function showSyncOnlySuccess(
        string $releaseName,
        float $duration,
        ?SyncDiff $syncDiff = null,
        ?SyncStats $syncStats = null,
        int $migrationsRun = 0,
        array $stepTimings = [],
        ?array $gitInfo = null,
        array $warnings = []
    ): void {
        $this->output->writeln('');

        $rows = [
            $this->formatRow('Environment', $this->config->environment->value),
            $this->formatRow('Release', $releaseName.' (existing)'),
        ];

        // Add git info if available
        if ($gitInfo !== null && ! empty($gitInfo['commit'])) {
            $branch = $gitInfo['branch'];
            $commit = $gitInfo['commit'];
            $rows[] = $this->formatRow('Git', "{$branch} @ {$commit}");
        }

        $rows[] = $this->formatRow('Duration', format_duration($duration));
        $rows[] = $this->formatFilesRow($syncDiff, $syncStats);

        if ($migrationsRun > 0) {
            $rows[] = $this->formatRow('Migrations', "{$migrationsRun} executed");
        }

        // Add mode indicator
        $rows[] = $this->formatRow('Mode', 'Sync-Only (no new release)');

        // Add warnings section if any
        if (! empty($warnings)) {
            $rows[] = '__separator__';
            $rows = array_merge($rows, $this->formatWarningsSection($warnings));
        }

        $this->drawBox('SYNC-ONLY COMPLETE', 'yellow', $rows);
        $this->output->writeln('');

        // Show step timings if available and verbose
        if (! empty($stepTimings) && $this->output->isVerbose()) {
            $this->showStepTimings($stepTimings);
        }
    }

    /**
     * Display step timings breakdown
     *
     * @param  array<string, float>  $timings
     */
    private function showStepTimings(array $timings): void
    {
        $this->output->writeln('<fg=cyan>Step Timings:</>');

        // Filter out very fast steps (< 100ms) and sort by duration descending
        $significantTimings = collect($timings)
            ->filter(fn ($duration) => $duration >= 0.1)
            ->sortDesc();

        foreach ($significantTimings as $step => $duration) {
            $formattedDuration = format_duration($duration);
            $paddedStep = str_pad($step, 20);
            $this->output->writeln("  {$paddedStep} <fg=gray>{$formattedDuration}</>");
        }

        $this->output->writeln('');
    }

    /**
     * Display the deployment failure summary
     */
    public function showFailure(
        string $errorMessage,
        float $duration,
        ?string $failedStep = null
    ): void {
        $this->output->writeln('');
        $this->drawBox('DEPLOYMENT FAILED', 'red', [
            $this->formatRow('Environment', $this->config->environment->value),
            $this->formatRow('Duration', format_duration($duration)),
            $failedStep ? $this->formatRow('Failed Step', $failedStep) : null,
            '',
            $this->formatRow('Error', $this->truncate($errorMessage, 40)),
        ]);
        $this->output->writeln('');
    }

    /**
     * Draw a decorated box with title and content
     */
    private function drawBox(string $title, string $color, array $rows): void
    {
        $innerWidth = self::INNER_WIDTH;

        // Top border
        $this->output->writeln("<fg={$color}>╔".str_repeat('═', $innerWidth).'╗</>');

        // Title
        $titlePadded = $this->centerText($title, $innerWidth);
        $this->output->writeln("<fg={$color}>║</><fg=white;options=bold>{$titlePadded}</><fg={$color}>║</>");

        // Separator
        $this->output->writeln("<fg={$color}>╠".str_repeat('═', $innerWidth).'╣</>');

        // Content rows
        foreach ($rows as $row) {
            if ($row === null) {
                continue;
            }

            if ($row === '') {
                // Empty row for spacing
                $this->output->writeln("<fg={$color}>║".str_repeat(' ', $innerWidth).'║</>');

                continue;
            }

            if ($row === '__separator__') {
                // Draw separator line (for warnings section, etc.)
                $this->output->writeln("<fg={$color}>╠".str_repeat('═', $innerWidth).'╣</>');

                continue;
            }

            $this->output->writeln("<fg={$color}>║</> {$row} <fg={$color}>║</>");
        }

        // Bottom border
        $this->output->writeln("<fg={$color}>╚".str_repeat('═', $innerWidth).'╝</>');
    }

    /**
     * Format a key-value row
     */
    private function formatRow(string $label, string $value): string
    {
        $labelWidth = 14;
        // Content width = inner width - 2 spaces (one after ║ and one before ║)
        $contentWidth = self::INNER_WIDTH - 2;
        $valueWidth = $contentWidth - $labelWidth;

        $labelFormatted = str_pad($label.':', $labelWidth);
        $valueFormatted = str_pad($this->truncate($value, $valueWidth), $valueWidth);

        return "<fg=gray>{$labelFormatted}</><fg=cyan>{$valueFormatted}</>";
    }

    /**
     * Format the files sync row with colored counts and transfer size
     */
    private function formatFilesRow(?SyncDiff $syncDiff, ?SyncStats $syncStats = null): string
    {
        $labelWidth = 14;
        $contentWidth = self::INNER_WIDTH - 2;
        $valueWidth = $contentWidth - $labelWidth;

        // Use actual stats if available, fall back to diff for display
        $hasChanges = ($syncStats !== null && $syncStats->hasChanges())
            || ($syncDiff !== null && ! $syncDiff->isEmpty());

        if (! $hasChanges) {
            return $this->formatRow('Files', 'No changes');
        }

        $parts = [];

        // Use syncDiff for +/~/- counts (shows intent)
        if ($syncDiff !== null) {
            if ($syncDiff->hasNew()) {
                $parts[] = "<fg=green>+{$syncDiff->newCount()}</>";
            }

            if ($syncDiff->hasModified()) {
                $parts[] = "<fg=yellow>~{$syncDiff->modifiedCount()}</>";
            }

            if ($syncDiff->hasDeleted()) {
                $parts[] = "<fg=red>-{$syncDiff->deletedCount()}</>";
            }
        }

        // Build summary with actual transfer info from syncStats
        if ($syncStats !== null && $syncStats->hasChanges()) {
            $summary = implode(' ', $parts);
            if (! empty($parts)) {
                $summary .= ' ';
            }
            $summary .= "<fg=gray>({$syncStats->filesSynced} synced, {$syncStats->getFormattedSize()})</>";
        } else {
            $total = $syncDiff?->totalCount() ?? 0;
            $summary = implode(' ', $parts)." <fg=gray>({$total} total)</>";
        }

        $labelFormatted = str_pad('Files:', $labelWidth);

        // Calculate visible length (without ANSI codes)
        $visibleValue = preg_replace('/<[^>]+>/', '', $summary);
        $padding = $valueWidth - mb_strlen($visibleValue);

        return "<fg=gray>{$labelFormatted}</>{$summary}".str_repeat(' ', max(0, $padding));
    }

    /**
     * Format the warnings section
     *
     * @param  array<array{category: string, message: string}>  $warnings
     * @return array<string|null>
     */
    private function formatWarningsSection(array $warnings): array
    {
        $contentWidth = self::INNER_WIDTH - 2;
        $rows = [];

        // Warning header
        $header = '<fg=yellow>⚠ Warnings</>';
        $headerVisible = '⚠ Warnings';
        $padding = $contentWidth - mb_strlen($headerVisible);
        $rows[] = $header.str_repeat(' ', max(0, $padding));

        // Each warning
        foreach ($warnings as $warning) {
            $bullet = "  • {$warning['message']}";
            $rows[] = "<fg=yellow>{$this->truncate($bullet, $contentWidth)}</>".
                str_repeat(' ', max(0, $contentWidth - mb_strlen($this->truncate($bullet, $contentWidth))));
        }

        return $rows;
    }

    /**
     * Center text within a given width
     */
    private function centerText(string $text, int $width): string
    {
        $textLen = mb_strlen($text);
        if ($textLen >= $width) {
            return substr($text, 0, $width);
        }
        $padding = (int) (($width - $textLen) / 2);
        $rightPadding = $width - $textLen - $padding;

        return str_repeat(' ', $padding).$text.str_repeat(' ', $rightPadding);
    }

    /**
     * Truncate a string to a maximum length
     */
    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3).'...';
    }

    /**
     * Create a summary instance
     */
    public static function create(OutputInterface $output, DeploymentConfig $config): self
    {
        return new self($output, $config);
    }
}
