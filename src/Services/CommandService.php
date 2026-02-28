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

    private ?float $sshConnectionTime = null;

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

    /**
     * Get SSH connection establishment time (if available)
     */
    public function getSshConnectionTime(): ?float
    {
        return $this->sshConnectionTime;
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
            $errorOutput = trim($process->getErrorOutput());

            if (! $process->isSuccessful()) {
                // Include both stdout and stderr for better error context
                // Artisan commands often output errors to stdout, not stderr
                $errorContext = $errorOutput ?: $output;
                throw SSHConnectionException::commandFailed($command, $errorContext);
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
     * Execute a remote command with output streamed at verbose level (-v).
     * Use this for long-running commands like composer install where
     * seeing progress is important for debugging.
     */
    public function remoteWithOutput(string $command): string
    {
        if ($this->config->isLocal) {
            return $this->localWithOutput($command);
        }

        $this->logCommand($command);

        try {
            $process = $this->ssh->execute($command);
            $output = trim($process->getOutput());
            $errorOutput = trim($process->getErrorOutput());

            // Show output at verbose level (not just very verbose)
            if ($this->output->isVerbose()) {
                $this->streamOutput($output);
                if (! empty($errorOutput)) {
                    $this->streamOutput($errorOutput, isError: true);
                }
            }

            if (! $process->isSuccessful()) {
                throw SSHConnectionException::commandFailed(
                    $command,
                    $errorOutput ?: $output
                );
            }

            return $output;
        } catch (\Exception $e) {
            if ($e instanceof SSHConnectionException) {
                throw $e;
            }
            throw SSHConnectionException::commandFailed($command, $e->getMessage());
        }
    }

    /**
     * Execute a local command with output streamed at verbose level (-v).
     */
    public function localWithOutput(string $command): string
    {
        $this->logCommand($command);

        $process = Process::fromShellCommandline(
            $command,
            $this->workingDirectory ?: base_path()
        );
        $process->setTimeout($this->timeout);
        $process->run();

        $output = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());

        // Show output at verbose level
        if ($this->output->isVerbose()) {
            $this->streamOutput($output);
            if (! empty($errorOutput)) {
                $this->streamOutput($errorOutput, isError: true);
            }
        }

        if (! $process->isSuccessful()) {
            throw TaskExecutionException::commandFailed($command, $errorOutput ?: $output);
        }

        return $output;
    }

    /**
     * Stream command output line by line with proper formatting.
     */
    private function streamOutput(string $output, bool $isError = false): void
    {
        if (empty(trim($output))) {
            return;
        }

        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (! empty($trimmedLine)) {
                if ($isError) {
                    $this->output->writeln("<comment>{$this->prefix}  {$trimmedLine}</comment>");
                } else {
                    $this->output->writeln("{$this->prefix}  {$trimmedLine}");
                }
            }
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

    /**
     * Run database migrations with verbose output parsing
     *
     * @return array{output: string, count: int, migrations: array<string>}
     */
    public function artisanMigrate(string $releasePath, bool $force = true): array
    {
        $optionsString = $force ? ' --force' : '';
        $fullCommand = "{$this->config->phpBinary} {$releasePath}/artisan migrate{$optionsString}";

        $this->info('Running artisan migrate');

        try {
            $output = $this->remote($fullCommand);
            $result = $this->parseMigrationOutput($output);

            // Show verbose migration details if enabled
            if ($this->output->isVerbose() && ! empty($result['migrations'])) {
                foreach ($result['migrations'] as $migration) {
                    $this->output->writeln("{$this->prefix}  → {$migration}");
                }
            }

            if ($result['count'] > 0) {
                $this->success("{$result['count']} migration(s) executed");
            } else {
                $this->info('Nothing to migrate');
            }

            return $result;
        } catch (\Exception $e) {
            throw TaskExecutionException::artisanFailed('migrate', $e->getMessage());
        }
    }

    /**
     * Parse migration command output to extract executed migrations
     *
     * @return array{output: string, count: int, migrations: array<string>}
     */
    private function parseMigrationOutput(string $output): array
    {
        $migrations = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);

            // Match migration lines like "2025_01_15_create_users_table ... DONE"
            // or "Running migration: 2025_01_15_create_users_table"
            if (preg_match('/(\d{4}_\d{2}_\d{2}_\d{6}_[a-z_]+)/i', $line, $matches)) {
                $migrations[] = $matches[1];
            }
            // Also match Laravel's newer format "Migrating: ..."
            elseif (preg_match('/(?:Migrating|Running):\s*(.+)/i', $line, $matches)) {
                $migrations[] = trim($matches[1]);
            }
        }

        // Remove duplicates (migration might appear in both "Running" and "Done" lines)
        $migrations = array_unique($migrations);

        return [
            'output' => $output,
            'count' => count($migrations),
            'migrations' => array_values($migrations),
        ];
    }

    // ============================================================
    // Service Control Methods
    // ============================================================

    /**
     * Restart all running PHP-FPM services (auto-detects versions)
     *
     * @return array<string> List of restarted services
     */
    public function restartPhpFpm(): array
    {
        $output = trim($this->remote(
            'systemctl list-units --type=service --state=running | grep -o "php[0-9.]*-fpm" || echo ""'
        ));

        if (empty($output)) {
            return [];
        }

        $services = array_filter(array_map('trim', explode("\n", $output)));
        $restarted = [];

        foreach ($services as $service) {
            $this->remote("sudo systemctl restart {$service}");
            $this->success("Restarted {$service}");
            $restarted[] = $service;
        }

        return $restarted;
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

    public function info(string $message): self
    {
        if ($this->shouldShowNormal()) {
            $this->output->writeln("<info>{$this->prefix}{$message}</info>");
        }

        return $this;
    }

    public function success(string $message): self
    {
        if ($this->shouldShowNormal()) {
            $this->output->writeln("<info>{$this->prefix}✓ {$message}</info>");
        }

        return $this;
    }

    public function error(string $message): self
    {
        $this->output->writeln("<error>{$this->prefix}{$message}</error>");

        return $this;
    }

    public function warning(string $message): self
    {
        if ($this->shouldShowNormal()) {
            $this->output->writeln("<comment>{$this->prefix}⚠ {$message}</comment>");
        }

        return $this;
    }

    public function comment(string $message): self
    {
        if ($this->shouldShowNormal()) {
            $this->output->writeln("<comment>{$this->prefix}{$message}</comment>");
        }

        return $this;
    }

    public function debug(string $message): self
    {
        if ($this->output->isDebug()) {
            $this->output->writeln("<comment>{$this->prefix}[DEBUG] {$message}</comment>");
        }

        return $this;
    }

    public function task(string $name): self
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln("\n<fg=cyan>task {$name}</>");
        }

        return $this;
    }

    public function line(string $message): self
    {
        if ($this->shouldShowNormal()) {
            $this->output->writeln($this->prefix.$message);
        }

        return $this;
    }

    public function newLine(int $count = 1): self
    {
        if ($this->shouldShowNormal()) {
            for ($i = 0; $i < $count; $i++) {
                $this->output->writeln('');
            }
        }

        return $this;
    }

    public function section(string $title): self
    {
        if ($this->shouldShowNormal()) {
            $this->newLine();
            $this->output->writeln('<fg=cyan>═══════════════════════════════════════════════════════════</>');
            $this->output->writeln("<fg=cyan>  {$title}</>");
            $this->output->writeln('<fg=cyan>═══════════════════════════════════════════════════════════</>');
            $this->newLine();
        }

        return $this;
    }

    public function write(string $message): self
    {
        if ($this->shouldShowNormal()) {
            $this->output->write($message);
        }

        return $this;
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

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        if ($this->ssh) {
            $this->ssh->setTimeout($timeout);
        }

        return $this;
    }

    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function isLocal(): bool
    {
        return $this->config->isLocal;
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    /**
     * Get the output interface
     */
    public function getOutput(): OutputInterface
    {
        return $this->output;
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

        if ($this->config->port !== null) {
            $this->ssh->usePort($this->config->port);
        }

        // Enable SSH connection multiplexing for performance
        // Reuses a single connection for all commands instead of reconnecting each time
        // Control socket stored in /tmp with user@host:port format for uniqueness
        $controlPath = '/tmp/deployer-ssh-%r@%h:%p';
        $this->ssh->useMultiplexing($controlPath, '60');

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
     * Test SSH connection and measure connection time.
     * Runs a simple command to establish the connection and times it.
     */
    public function testConnection(): bool
    {
        if ($this->config->isLocal) {
            return true;
        }

        $startTime = microtime(true);

        try {
            $this->remote('echo "connected"');
            $this->sshConnectionTime = microtime(true) - $startTime;

            if ($this->output->isVerbose()) {
                $formatted = number_format($this->sshConnectionTime, 2);
                $this->output->writeln("{$this->prefix}SSH connected in {$formatted}s");
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
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

        // Mask GitHub tokens - classic PATs (ghp_, gho_, ghs_, ghr_)
        $command = preg_replace('/gh[pors]_[A-Za-z0-9_]+/', 'gh*_***', $command);

        // Mask GitHub tokens - fine-grained PATs (github_pat_)
        $command = preg_replace('/github_pat_[A-Za-z0-9_]+/', 'github_pat_***', $command);

        // Mask entire github-oauth JSON blocks (catches any token format in auth.json)
        $command = preg_replace('/"github-oauth":\s*\{[^}]+\}/', '"github-oauth":{"github.com":"***"}', $command);

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
