<?php

namespace Shaf\LaravelDeployer\Support;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Interactive mode for deployment.
 * Prompts user for various deployment options before proceeding.
 */
class InteractiveMode
{
    private array $options = [];

    public function __construct(
        private InputInterface $input,
        private OutputInterface $output,
        private DeploymentConfig $config
    ) {}

    /**
     * Run the interactive prompts and return selected options
     *
     * @return array{
     *     buildAssets: bool,
     *     runMigrations: bool,
     *     optimizeApp: bool,
     *     showDiff: bool,
     *     confirmChanges: bool
     * }
     */
    public function prompt(): array
    {
        $this->output->writeln('');
        $this->output->writeln('<fg=cyan>═══════════════════════════════════════════════════════════</>');
        $this->output->writeln('<fg=cyan>                   INTERACTIVE MODE</>');
        $this->output->writeln('<fg=cyan>═══════════════════════════════════════════════════════════</>');
        $this->output->writeln('');
        $this->output->writeln("<fg=gray>  Environment: </><fg=yellow>{$this->config->environment->value}</>");
        $this->output->writeln("<fg=gray>  Server:      </><fg=yellow>{$this->config->hostname}</>");
        $this->output->writeln('');
        $this->output->writeln('<fg=gray>  Configure your deployment options below:</>');
        $this->output->writeln('');

        $helper = new QuestionHelper;

        // Build assets
        $this->options['buildAssets'] = $this->askConfirmation(
            $helper,
            'Build frontend assets locally?',
            ! $this->config->isLocal
        );

        // Run migrations
        $this->options['runMigrations'] = $this->askConfirmation(
            $helper,
            'Run database migrations?',
            true
        );

        // Optimize app
        $this->options['optimizeApp'] = $this->askConfirmation(
            $helper,
            'Optimize application (config:cache, route:cache)?',
            true
        );

        // Show diff
        $this->options['showDiff'] = $this->askConfirmation(
            $helper,
            'Show file changes before uploading?',
            $this->config->showDiff
        );

        // Confirm changes
        $this->options['confirmChanges'] = $this->askConfirmation(
            $helper,
            'Require confirmation before uploading?',
            $this->config->confirmChanges
        );

        $this->output->writeln('');
        $this->output->writeln('<fg=cyan>═══════════════════════════════════════════════════════════</>');
        $this->output->writeln('');

        // Show summary of selected options
        $this->showSummary();

        // Final confirmation
        $proceed = $this->askConfirmation(
            $helper,
            'Proceed with deployment?',
            true
        );

        if (! $proceed) {
            throw new \RuntimeException('Deployment cancelled by user');
        }

        return $this->options;
    }

    /**
     * Ask a confirmation question
     */
    private function askConfirmation(QuestionHelper $helper, string $question, bool $default): bool
    {
        $defaultText = $default ? 'Y/n' : 'y/N';
        $prompt = "  <fg=white>{$question}</> <fg=gray>[{$defaultText}]</> ";

        $confirmQuestion = new ConfirmationQuestion($prompt, $default);

        return $helper->ask($this->input, $this->output, $confirmQuestion);
    }

    /**
     * Show summary of selected options
     */
    private function showSummary(): void
    {
        $this->output->writeln('<fg=white>  Selected options:</>');
        $this->output->writeln('');

        $options = [
            'buildAssets' => 'Build assets',
            'runMigrations' => 'Run migrations',
            'optimizeApp' => 'Optimize app',
            'showDiff' => 'Show diff',
            'confirmChanges' => 'Confirm changes',
        ];

        foreach ($options as $key => $label) {
            $value = $this->options[$key] ?? false;
            $icon = $value ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->output->writeln("    {$icon} {$label}");
        }

        $this->output->writeln('');
    }

    /**
     * Get the selected options
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Check if a specific option is enabled
     */
    public function isEnabled(string $option): bool
    {
        return $this->options[$option] ?? false;
    }
}
