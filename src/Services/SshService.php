<?php

namespace Shaf\LaravelDeployer\Services;

use Closure;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\SshResult;
use Symfony\Component\Process\Process;

class SshService
{
    private string $host;

    private string $user;

    private ?int $port;

    private ?string $identityFile;

    private bool $strictHostKeyChecking;

    private int $timeout;

    private bool $multiplexingEnabled = false;

    private ?string $controlPath = null;

    public function __construct(
        string $host,
        string $user,
        ?int $port = null,
        ?string $identityFile = null,
        bool $strictHostKeyChecking = true,
        int $timeout = 900,
    ) {
        $this->host = $host;
        $this->user = $user;
        $this->port = $port;
        $this->identityFile = $identityFile ? $this->expandTilde($identityFile) : null;
        $this->strictHostKeyChecking = $strictHostKeyChecking;
        $this->timeout = $timeout;
    }

    public static function fromConfig(DeploymentConfig $config): static
    {
        $service = new static(
            host: $config->hostname,
            user: $config->remoteUser,
            port: $config->port,
            identityFile: $config->identityFile,
            strictHostKeyChecking: $config->strictHostKeyChecking,
            timeout: 900,
        );

        if (! static::isWindows()) {
            $service->enableMultiplexing();
        }

        return $service;
    }

    public static function fromArray(array $config): static
    {
        return new static(
            host: $config['host'] ?? $config['hostname'] ?? 'localhost',
            user: $config['user'] ?? $config['remoteUser'] ?? 'root',
            port: $config['port'] ?? null,
            identityFile: $config['identityFile'] ?? $config['key'] ?? null,
            strictHostKeyChecking: $config['strictHostKeyChecking'] ?? false,
            timeout: $config['timeout'] ?? 900,
        );
    }

    // ============================================================
    // SSH Execution
    // ============================================================

    public function ssh(string|array $commands): SshResult
    {
        $commandString = is_array($commands) ? implode(PHP_EOL, $commands) : $commands;

        if (static::isWindows()) {
            return $this->executeWindowsSsh($commandString);
        }

        $sshCommand = $this->buildSshCommandString($commandString);

        return $this->runProcess($sshCommand);
    }

    public function sshWithOutput(string|array $commands, Closure $onOutput): SshResult
    {
        $commandString = is_array($commands) ? implode(PHP_EOL, $commands) : $commands;

        if (static::isWindows()) {
            return $this->executeWindowsSsh($commandString, $onOutput);
        }

        $sshCommand = $this->buildSshCommandString($commandString);

        return $this->runProcess($sshCommand, onOutput: $onOutput);
    }

    public function test(string $condition): bool
    {
        $result = $this->ssh($condition.' && echo "true" || echo "false"');
        $output = trim($result->output);

        return $output === 'true' || str_ends_with($output, "\ntrue");
    }

    public function testConnection(): bool
    {
        try {
            $result = $this->ssh('echo "connected"');

            return $result->successful;
        } catch (\Exception) {
            return false;
        }
    }

    // ============================================================
    // File Transfer (SCP)
    // ============================================================

    public function upload(string $localPath, string $remotePath): SshResult
    {
        if (static::isWindows()) {
            return $this->runProcessArray($this->buildWindowsScpArgs($localPath, "{$this->getTarget()}:{$remotePath}"));
        }

        return $this->runProcess($this->buildScpCommandString($localPath, "{$this->getTarget()}:{$remotePath}"));
    }

    public function download(string $remotePath, string $localPath): SshResult
    {
        if (static::isWindows()) {
            return $this->runProcessArray($this->buildWindowsScpArgs("{$this->getTarget()}:{$remotePath}", $localPath));
        }

        return $this->runProcess($this->buildScpCommandString("{$this->getTarget()}:{$remotePath}", $localPath));
    }

    // ============================================================
    // Rsync
    // ============================================================

    public function rsync(string $source, string $dest, array $excludes = [], array $includes = [], array $extraFlags = [], ?Closure $onOutput = null, ?int $timeout = null, ?string $filesFromPath = null): SshResult
    {
        $command = $this->buildRsyncCommandString($source, $dest, $excludes, $includes, $extraFlags, $filesFromPath);

        if (static::isWindows()) {
            return $this->runWslRsync($command, $timeout, $onOutput);
        }

        return $this->runProcess($command, timeout: $timeout, onOutput: $onOutput);
    }

    public function rsyncDryRun(string $source, string $dest, array $excludes = [], array $includes = [], array $extraFlags = []): SshResult
    {
        $extraFlags[] = 'dry-run';
        $extraFlags[] = 'itemize-changes';

        $command = $this->buildRsyncCommandString($source, $dest, $excludes, $includes, $extraFlags);

        if (static::isWindows()) {
            return $this->runWslRsync($command);
        }

        return $this->runProcess($command);
    }

    // ============================================================
    // Multiplexing
    // ============================================================

    public function enableMultiplexing(): static
    {
        $this->multiplexingEnabled = true;
        $this->controlPath = sys_get_temp_dir().'/deployer-ssh-%r@%h:%p';

        return $this;
    }

    public function disableMultiplexing(): static
    {
        $this->multiplexingEnabled = false;
        $this->controlPath = null;

        return $this;
    }

    public function cleanupSockets(): void
    {
        if (! $this->multiplexingEnabled || ! $this->controlPath || static::isWindows()) {
            return;
        }

        $port = $this->port ?? 22;
        $socketPath = str_replace(
            ['%r', '%h', '%p'],
            [$this->user, $this->host, $port],
            $this->controlPath
        );

        @shell_exec("ssh -O exit -o ControlPath={$socketPath} {$this->getTarget()} 2>/dev/null || true");
    }

    // ============================================================
    // Configuration
    // ============================================================

    public function setTimeout(int $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getIdentityFile(): ?string
    {
        return $this->identityFile;
    }

    // ============================================================
    // SSH Options Building
    // ============================================================

    public function buildSshOptions(): array
    {
        $options = [];

        if ($this->port !== null) {
            $options[] = '-p';
            $options[] = (string) $this->port;
        }

        if ($this->identityFile) {
            if (! file_exists($this->identityFile)) {
                throw new \RuntimeException("SSH identity file not found: {$this->identityFile}");
            }
            $options[] = '-i';
            $options[] = $this->identityFile;
        }

        if (! $this->strictHostKeyChecking) {
            $options[] = '-o';
            $options[] = 'StrictHostKeyChecking=no';
            $options[] = '-o';
            $options[] = 'UserKnownHostsFile=/dev/null';
        }

        $options[] = '-o';
        $options[] = 'PasswordAuthentication=no';

        if ($this->multiplexingEnabled && $this->controlPath) {
            $options[] = '-o';
            $options[] = 'ControlMaster=auto';
            $options[] = '-o';
            $options[] = "ControlPath={$this->controlPath}";
            $options[] = '-o';
            $options[] = 'ControlPersist=60';
        }

        return $options;
    }

    public function buildSshOptionsString(): string
    {
        $options = [];

        if ($this->port !== null) {
            $options[] = "-p {$this->port}";
        }

        if ($this->identityFile) {
            $options[] = "-i {$this->identityFile}";
        }

        if (! $this->strictHostKeyChecking) {
            $options[] = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
        }

        $options[] = '-o PasswordAuthentication=no';

        if ($this->multiplexingEnabled && $this->controlPath) {
            $options[] = "-o ControlMaster=auto -o ControlPath={$this->controlPath} -o ControlPersist=60";
        }

        return implode(' ', $options);
    }

    /**
     * Build the SSH options string for use in rsync's -e flag.
     * Uses -A for agent forwarding.
     */
    public function buildRsyncSshOptions(): string
    {
        $parts = ['ssh', '-A'];

        if ($this->port !== null) {
            $parts[] = "-p {$this->port}";
        }

        if ($this->identityFile) {
            $parts[] = "-i {$this->identityFile}";
        }

        if (! $this->strictHostKeyChecking) {
            $parts[] = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
        }

        if ($this->multiplexingEnabled && $this->controlPath) {
            $parts[] = "-o ControlMaster=auto -o ControlPersist=60 -o ControlPath={$this->controlPath}";
        }

        return implode(' ', $parts);
    }

    public function getTarget(): string
    {
        return "{$this->user}@{$this->host}";
    }

    // ============================================================
    // Platform Detection
    // ============================================================

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public function expandTilde(string $path): string
    {
        if (! str_starts_with($path, '~')) {
            return $path;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: (static::isWindows()
            ? ($_SERVER['USERPROFILE'] ?? getenv('USERPROFILE') ?: '')
            : '/tmp');

        return str_replace('~', $home, $path);
    }

    public static function windowsPathToWsl(string $path): string
    {
        if (preg_match('/^([A-Za-z]):[\\\\\\/]/', $path, $matches)) {
            $drive = strtolower($matches[1]);
            $path = preg_replace('/^[A-Za-z]:[\\\\\\/]/', "/mnt/{$drive}/", $path);
            $path = str_replace('\\', '/', $path);
        }

        return $path;
    }

    // ============================================================
    // Private: Command Building
    // ============================================================

    private function buildSshCommandString(string $commandString): string
    {
        $options = $this->buildSshOptionsString();
        $target = $this->getTarget();
        $delimiter = 'EOF-DEPLOYER-SSH';

        return "ssh {$options} {$target} 'bash -se' << \\{$delimiter}".PHP_EOL
            .$commandString.PHP_EOL
            .$delimiter;
    }

    private function buildScpCommandString(string $source, string $destination): string
    {
        $options = $this->buildScpOptionsString();

        return "scp {$options} {$source} {$destination}";
    }

    private function buildScpOptionsString(): string
    {
        $options = ['-r'];

        if ($this->port !== null) {
            $options[] = "-P {$this->port}";
        }

        if ($this->identityFile) {
            $options[] = "-i {$this->identityFile}";
        }

        if (! $this->strictHostKeyChecking) {
            $options[] = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
        }

        $options[] = '-o PasswordAuthentication=no';

        return implode(' ', $options);
    }

    private function buildRsyncCommandString(string $source, string $dest, array $excludes = [], array $includes = [], array $extraFlags = [], ?string $filesFromPath = null): string
    {
        $parts = ['rsync'];

        // Base flags
        $flags = 'rzc';
        if (in_array('verbose', $extraFlags)) {
            $flags .= 'v';
            $extraFlags = array_diff($extraFlags, ['verbose']);
        }
        $parts[] = '-'.$flags;

        // SSH transport options (remote only — detect by checking if dest contains @)
        if (str_contains($dest, '@') || str_contains($source, '@')) {
            $sshOptions = $this->buildRsyncSshOptions();
            $parts[] = "-e '{$sshOptions}'";
        }

        // Extra flags like --stats, --dry-run, --itemize-changes
        foreach ($extraFlags as $flag) {
            $parts[] = "--{$flag}";
        }

        // Files-from mode skips delete options
        if ($filesFromPath !== null) {
            $parts[] = "--files-from='{$filesFromPath}'";
        }

        // Includes before excludes
        foreach ($includes as $include) {
            $parts[] = "--include='{$include}'";
        }

        foreach ($excludes as $exclude) {
            $parts[] = "--exclude='{$exclude}'";
        }

        $parts[] = "'{$source}'";
        $parts[] = "'{$dest}'";

        return implode(' ', $parts);
    }

    // ============================================================
    // Private: Windows Execution
    // ============================================================

    private function executeWindowsSsh(string $commandString, ?Closure $onOutput = null): SshResult
    {
        $sshBinary = 'C:\\Windows\\System32\\OpenSSH\\ssh.exe';
        $args = array_merge([$sshBinary], $this->buildSshOptions(), [$this->getTarget(), $commandString]);

        return $this->runProcessArray($args, onOutput: $onOutput);
    }

    private function buildWindowsScpArgs(string $source, string $destination): array
    {
        $scpBinary = 'C:\\Windows\\System32\\OpenSSH\\scp.exe';
        $args = [$scpBinary, '-r'];

        if ($this->port !== null) {
            $args[] = '-P';
            $args[] = (string) $this->port;
        }

        if ($this->identityFile) {
            $args[] = '-i';
            $args[] = $this->identityFile;
        }

        if (! $this->strictHostKeyChecking) {
            $args[] = '-o';
            $args[] = 'StrictHostKeyChecking=no';
            $args[] = '-o';
            $args[] = 'UserKnownHostsFile=/dev/null';
        }

        $args[] = '-o';
        $args[] = 'PasswordAuthentication=no';
        $args[] = $source;
        $args[] = $destination;

        return $args;
    }

    private function runWslRsync(string $rsyncCommand, ?int $timeout = null, ?Closure $onOutput = null): SshResult
    {
        // Wrap the rsync command in WSL
        $wslCommand = 'wsl '.$rsyncCommand;

        return $this->runProcess($wslCommand, timeout: $timeout, onOutput: $onOutput);
    }

    // ============================================================
    // Private: Process Execution
    // ============================================================

    private function runProcess(string $command, ?int $timeout = null, ?Closure $onOutput = null): SshResult
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout($timeout ?? $this->timeout);

        if ($onOutput) {
            $process->run($onOutput);
        } else {
            $process->run();
        }

        return new SshResult(
            successful: $process->isSuccessful(),
            exitCode: $process->getExitCode() ?? -1,
            output: trim($process->getOutput()),
            errorOutput: trim($process->getErrorOutput()),
        );
    }

    private function runProcessArray(array $args, ?int $timeout = null, ?Closure $onOutput = null): SshResult
    {
        $process = new Process($args);
        $process->setTimeout($timeout ?? $this->timeout);

        if ($onOutput) {
            $process->run($onOutput);
        } else {
            $process->run();
        }

        return new SshResult(
            successful: $process->isSuccessful(),
            exitCode: $process->getExitCode() ?? -1,
            output: trim($process->getOutput()),
            errorOutput: trim($process->getErrorOutput()),
        );
    }
}
