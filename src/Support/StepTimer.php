<?php

namespace Shaf\LaravelDeployer\Support;

/**
 * Tracks timing for deployment steps.
 * Provides per-step duration tracking and formatted output.
 */
class StepTimer
{
    /** @var array<string, array{start: float, end: ?float}> */
    private array $steps = [];

    private ?string $currentStep = null;

    /**
     * Start timing a step
     */
    public function start(string $stepName): void
    {
        // End any currently running step
        if ($this->currentStep !== null) {
            $this->end($this->currentStep);
        }

        $this->steps[$stepName] = [
            'start' => microtime(true),
            'end' => null,
        ];
        $this->currentStep = $stepName;
    }

    /**
     * End timing for a step
     */
    public function end(string $stepName): void
    {
        if (isset($this->steps[$stepName]) && $this->steps[$stepName]['end'] === null) {
            $this->steps[$stepName]['end'] = microtime(true);
        }

        if ($this->currentStep === $stepName) {
            $this->currentStep = null;
        }
    }

    /**
     * End the current step (if any)
     */
    public function endCurrent(): void
    {
        if ($this->currentStep !== null) {
            $this->end($this->currentStep);
        }
    }

    /**
     * Get duration for a step in seconds
     */
    public function getDuration(string $stepName): ?float
    {
        if (! isset($this->steps[$stepName])) {
            return null;
        }

        $step = $this->steps[$stepName];
        $end = $step['end'] ?? microtime(true);

        return $end - $step['start'];
    }

    /**
     * Get all step timings
     *
     * @return array<string, float>
     */
    public function getTimings(): array
    {
        $timings = [];

        foreach ($this->steps as $name => $step) {
            $end = $step['end'] ?? microtime(true);
            $timings[$name] = $end - $step['start'];
        }

        return $timings;
    }

    /**
     * Get formatted timings for display
     *
     * @return array<string, string>
     */
    public function getFormattedTimings(): array
    {
        $formatted = [];

        foreach ($this->getTimings() as $name => $duration) {
            $formatted[$name] = format_duration($duration);
        }

        return $formatted;
    }

    /**
     * Reset all timings
     */
    public function reset(): void
    {
        $this->steps = [];
        $this->currentStep = null;
    }
}
