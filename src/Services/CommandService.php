<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Constants\Timeouts;
use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Exceptions\SSHConnectionException;
use Shaf\LaravelDeployer\Exceptions\TaskExecutionException;
use Spatie\Ssh\Ssh;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Unified service for command execution (local/remote) and output handling.
 * Implements CommandExecutor for standardized command execution.
 */
class CommandService implements CommandExecutor
{
    private ?Ssh $ssh = null;

    private int $timeout = Timeouts::DEFAULT_COMMAND;

    private string $prefix = '';

    private ?string $lastError = null;

    public function __construct(
        private DeploymentConfig $config,
        private OutputInterface $output,
        private string $workingDirectory = ''
    ) {
        if (! $config->isLocal) {
            $this->initializeSsh();
        }

        $this->prefix = "[{$config->environment->value}] ";
    }

    // ============================================================
    // Command Execution Methods
    // ============================================================

    /**
     * Execute a command (remote or local based on configuration)
     * Implements CommandExecutor interface
     */
    public function execute(string $command): string
    {
        return $this->remote($command);
    }

    /**
     * Execute a remote command via SSH
     */
    public function remote(string $command): string
    {
        if ($this->config->isLocal) {
            return $this->local($command);
        }

        $this->logCommand($command);

        try {
            $process = $this->ssh->execute($command);
            $output = trim($process->getOutput());

            if (! $process->isSuccessful()) {
                throw SSHConnectionException::commandFailed(
                    $command,
                    $process->getErrorOutput()
                );
            }

            $this->logCommandOutput($output);

            return $output;
        } catch (\Exception $e) {
            if ($e instanceof SSHConnectionException) {
                throw $e;
            }
            throw SSHConnectionException::commandFailed($command, $e->getMessage());
        }
    }

    /**
     * Execute multiple commands as a single batched remote call.
     * Commands are joined with && so execution stops on first failure.
     *
     * @param  array<string>  $commands  Array of commands to execute
     * @return string Output from the combined command
     */
    public function runBatch(array $commands): string
    {
        if (empty($commands)) {
            return '';
        }

        $batchedCommand = implode(' && ', $commands);

        return $this->remote($batchedCommand);
    }

    /**
     * Execute a local command
     */
    public function local(string $command): string
    {
        $this->logCommand($command);

        $process = Process::fromShellCommandline(
            $command,
            $this->workingDirectory ?: base_path()
        );
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw TaskExecutionException::commandFailed($command, $process->getErrorOutput());
        }

        $output = trim($process->getOutput());
        $this->logCommandOutput($output);

        return $output;
    }

    /**
     * Test a condition (returns true/false)
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function test(string $condition): bool
    {
        $this->lastError = null;

        try {
            if ($this->config->isLocal) {
                $process = Process::fromShellCommandline(
                    $condition,
                    $this->workingDirectory ?: base_path()
                );
                $process->run();

                return $process->isSuccessful();
            }

            $process = $this->ssh->execute($condition.' && echo "true" || echo "false"');

            // Capture any error output
            $errorOutput = trim($process->getErrorOutput());
            $stdout = trim($process->getOutput());

            if (! $process->isSuccessful() || $errorOutput) {
                $this->lastError = $errorOutput ?: "Exit code: {$process->getExitCode()}, stdout: {$stdout}";
            }

            // Check for exact "true" match or "true" on its own line
            // Avoid false positives like "truthful" or "untruthful"
            $trimmed = trim($stdout);

            return $trimmed === 'true' || str_ends_with($trimmed, "\ntrue");
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    // ============================================================
    // Artisan Command Methods
    // ============================================================

    /**
     * Run an artisan command
     */
    public function artisan(string $command, string $releasePath, array $options = [], bool $force = false): string
    {
        $optionsString = $this->buildArtisanOptions($options, $force);
        $fullCommand = "{$this->config->phpBinary} {$releasePath}/artisan {$command}{$optionsString}";

        $this->info("Running artisan {$command}");

        try {
            return $this->remote($fullCommand);
        } catch (\Exception $e) {
            throw TaskExecutionException::artisanFailed($command, $e->getMessage());
        }
    }

    /**
     * Artisan helper methods
     */
    public function artisanStorageLink(string $releasePath): string
    {
        return $this->artisan('storage:link', $releasePath);
    }

    public function artisanConfigCache(string $releasePath): string
    {
        return $this->artisan('config:cache', $releasePath);
    }

    public function artisanViewCache(string $releasePath): string
    {
        return $this->artisan('view:cache', $releasePath);
    }

    public function artisanRouteCache(string $releasePath): string
    {
        return $this->artisan('route:cache', $releasePath);
    }

    public function artisanOptimize(string $releasePath): string
    {
        return $this->artisan('optimize', $releasePath);
    }

    public function artisanMigrate(string $releasePath, bool $force = true): string
    {
        return $this->artisan('migrate', $releasePath, [], $force);
    }

    public function artisanQueueRestart(string $releasePath): string
    {
        return $this->artisan('queue:restart', $releasePath);
    }

    // ============================================================
    // File/Directory Test Methods
    // ============================================================

    public function fileExists(string $path): bool
    {
        $escapedPath = self::escapePath($path);

        return $this->test("[ -f {$escapedPath} ]");
    }

    public function directoryExists(string $path): bool
    {
        $escapedPath = self::escapePath($path);

        return $this->test("[ -d {$escapedPath} ]");
    }

    public function symlinkExists(string $path): bool
    {
        $escapedPath = self::escapePath($path);

        return $this->test("[ -L {$escapedPath} ]");
    }

    public function pathExists(string $path): bool
    {
        $escapedPath = self::escapePath($path);

        return $this->test("[ -e {$escapedPath} ]");
    }

    // ============================================================
    // Output Methods
    // ============================================================

    public function info(string $message): void
    {
        if ($this->shouldShowNormal()) {
            $this->output->writeln("<info>{$this->prefix}{$message}</info>");
        }
    }

    public function success(string $message): void
    {
        if ($this->shouldShowNormal()) {
            $this->output->writeln("<info>{$this->prefix}✓ {$message}</info>");
        }
    }

    public function error(string $message): void
    {
        $this->output->writeln("<error>{$this->prefix}{$message}</error>");
    }

    public function warning(string $message): void
    {
        if ($this->shouldShowNormal()) {
            $this->output->writeln("<comment>{$this->prefix}⚠ {$message}</comment>");
        }
    }

    public function comment(string $message): void
    {
        if ($this->shouldShowNormal()) {
            $this->output->writeln("<comment>{$this->prefix}{$message}</comment>");
        }
    }

    public function debug(string $message): void
    {
        if ($this->output->isDebug()) {
            $this->output->writeln("<comment>{$this->prefix}[DEBUG] {$message}</comment>");
        }
    }

    public function task(string $name): void
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln("\n<fg=cyan>task {$name}</>");
        }
    }

    public function line(string $message): void
    {
        if ($this->shouldShowNormal()) {
            $this->output->writeln($this->prefix.$message);
        }
    }

    public function newLine(int $count = 1): void
    {
        if ($this->shouldShowNormal()) {
            for ($i = 0; $i < $count; $i++) {
                $this->output->writeln('');
            }
        }
    }

    public function section(string $title): void
    {
        if ($this->shouldShowNormal()) {
            $this->newLine();
            $this->output->writeln('<fg=cyan>═══════════════════════════════════════════════════════════</>');
            $this->output->writeln("<fg=cyan>  {$title}</>");
            $this->output->writeln('<fg=cyan>═══════════════════════════════════════════════════════════</>');
            $this->newLine();
        }
    }

    public function write(string $message): void
    {
        if ($this->shouldShowNormal()) {
            $this->output->write($message);
        }
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $helper = new \Symfony\Component\Console\Helper\QuestionHelper;
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $confirmQuestion = new \Symfony\Component\Console\Question\ConfirmationQuestion(
            "{$this->prefix}{$question} ".($default ? '[Y/n]' : '[y/N]').' ',
            $default
        );

        return $helper->ask($input, $this->output, $confirmQuestion);
    }

    // ============================================================
    // Configuration Methods
    // ============================================================

    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
        if ($this->ssh) {
            $this->ssh->setTimeout($timeout);
        }
    }

    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    public function isLocal(): bool
    {
        return $this->config->isLocal;
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    // ============================================================
    // Verbosity Checks
    // ============================================================

    public function isQuiet(): bool
    {
        return $this->output->getVerbosity() === OutputInterface::VERBOSITY_QUIET;
    }

    public function isVerbose(): bool
    {
        return $this->output->isVerbose();
    }

    public function isVeryVerbose(): bool
    {
        return $this->output->isVeryVerbose();
    }

    public function isDebug(): bool
    {
        return $this->output->isDebug();
    }

    // ============================================================
    // Private Helper Methods
    // ============================================================

    private function initializeSsh(): void
    {
        $this->ssh = Ssh::create($this->config->remoteUser, $this->config->hostname);

        // Only disable strict host key checking if explicitly configured to do so
        // Default is true (enabled) for security - disabling allows MITM attacks
        if (! $this->config->strictHostKeyChecking) {
            $this->ssh->disableStrictHostKeyChecking();
        }

        // Use identity file if configured
        if ($this->config->identityFile) {
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?? '/tmp';
            $identityPath = str_starts_with($this->config->identityFile, '~')
                ? str_replace('~', $home, $this->config->identityFile)
                : $this->config->identityFile;

            // Verify file exists
            if (! file_exists($identityPath)) {
                throw new \RuntimeException("SSH identity file not found: {$identityPath}");
            }

            $this->ssh->usePrivateKey($identityPath);
        }

        $this->ssh->disablePasswordAuthentication();
        $this->ssh->setTimeout($this->timeout);
    }

    /**
     * Escape a path for safe use in shell commands.
     * Prevents command injection vulnerabilities.
     */
    public static function escapePath(string $path): string
    {
        return escapeshellarg($path);
    }

    private function buildArtisanOptions(array $options, bool $force): string
    {
        $parts = [];

        if ($force) {
            $parts[] = '--force';
        }

        foreach ($options as $key => $value) {
            if (is_int($key)) {
                $parts[] = $value;
            } elseif ($value === true) {
                $parts[] = "--{$key}";
            } elseif ($value !== false && $value !== null) {
                $parts[] = "--{$key}={$value}";
            }
        }

        return empty($parts) ? '' : ' '.implode(' ', $parts);
    }

    private function logCommand(string $command): void
    {
        if ($this->output->isVerbose()) {
            $maskedCommand = $this->maskSecrets($command);
            $this->output->writeln("<comment>{$this->prefix}run {$maskedCommand}</comment>");
        }
    }

    /**
     * Mask sensitive values in command strings before logging.
     * Prevents accidental exposure of passwords, tokens, and webhooks in verbose output.
     */
    private function maskSecrets(string $command): string
    {
        // Mask MySQL password: -p'password' or -ppassword
        $command = preg_replace("/-p'[^']*'/", "-p'***'", $command);
        $command = preg_replace('/-p[^\s\'"]+/', '-p***', $command);

        // Mask GitHub tokens (ghp_, gho_, ghs_, ghr_)
        $command = preg_replace('/gh[pors]_[A-Za-z0-9_]+/', 'gh*_***', $command);

        // Mask COMPOSER_AUTH JSON containing tokens
        $command = preg_replace('/COMPOSER_AUTH=\'[^\']+\'/', "COMPOSER_AUTH='***'", $command);

        // Mask Slack webhook URLs
        $command = preg_replace('/hooks\.slack\.com\/services\/[^\s"\']+/', 'hooks.slack.com/services/***', $command);

        // Mask Discord webhook URLs
        $command = preg_replace('/discord\.com\/api\/webhooks\/[^\s"\']+/', 'discord.com/api/webhooks/***', $command);

        // Mask generic password patterns in environment variables
        $command = preg_replace('/PASSWORD=[^\s]+/', 'PASSWORD=***', $command);

        return $command;
    }

    private function logCommandOutput(string $output): void
    {
        if ($this->output->isVeryVerbose() && ! empty(trim($output))) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                if (! empty(trim($line))) {
                    $this->output->writeln("{$this->prefix}{$line}");
                }
            }
        }
    }

    private function shouldShowNormal(): bool
    {
        return $this->output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL;
    }
}
