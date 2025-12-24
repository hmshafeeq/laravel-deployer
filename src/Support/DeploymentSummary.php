<?php

namespace Shaf\LaravelDeployer\Support;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\SyncDiff;
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
     */
    public function showSuccess(
        string $releaseName,
        float $duration,
        ?SyncDiff $syncDiff = null,
        int $migrationsRun = 0,
        ?string $url = null
    ): void {
        $this->output->writeln('');
        $this->drawBox('DEPLOYMENT COMPLETE', 'green', [
            $this->formatRow('Environment', $this->config->environment->value),
            $this->formatRow('Release', $releaseName),
            $this->formatRow('Duration', $this->formatDuration($duration)),
            $this->formatFilesRow($syncDiff),
            $migrationsRun > 0 ? $this->formatRow('Migrations', "{$migrationsRun} executed") : null,
            $url ? $this->formatRow('URL', $url) : null,
        ]);
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
            $this->formatRow('Duration', $this->formatDuration($duration)),
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
                $this->output->writeln("<fg={$color}>║".str_repeat(' ', $innerWidth)."║</>");

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
     * Format the files sync row with colored counts
     */
    private function formatFilesRow(?SyncDiff $syncDiff): ?string
    {
        $labelWidth = 14;
        $contentWidth = self::INNER_WIDTH - 2;
        $valueWidth = $contentWidth - $labelWidth;

        if ($syncDiff === null || $syncDiff->isEmpty()) {
            return $this->formatRow('Files', 'No changes');
        }

        $parts = [];

        if ($syncDiff->hasNew()) {
            $parts[] = "<fg=green>+{$syncDiff->newCount()}</>";
        }

        if ($syncDiff->hasModified()) {
            $parts[] = "<fg=yellow>~{$syncDiff->modifiedCount()}</>";
        }

        if ($syncDiff->hasDeleted()) {
            $parts[] = "<fg=red>-{$syncDiff->deletedCount()}</>";
        }

        $total = $syncDiff->totalCount();
        $summary = implode(' ', $parts)." <fg=gray>({$total} total)</>";

        $labelFormatted = str_pad('Files:', $labelWidth);

        // Calculate visible length (without ANSI codes)
        $visibleValue = preg_replace('/<[^>]+>/', '', $summary);
        $padding = $valueWidth - mb_strlen($visibleValue);

        return "<fg=gray>{$labelFormatted}</>{$summary}".str_repeat(' ', max(0, $padding));
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
     * Format duration in human-readable format
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return number_format($seconds, 1).'s';
        }

        $minutes = (int) ($seconds / 60);
        $secs = (int) ($seconds % 60);

        return "{$minutes}m {$secs}s";
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
