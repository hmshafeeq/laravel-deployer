<?php

namespace Shaf\LaravelDeployer\Services;

use Closure;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\SshResult;
use Shaf\LaravelDeployer\Exceptions\SSHConnectionException;
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
            host: $config['host'] ?? 'localhost',
            user: $config['user'] ?? 'root',
            port: $config['port'] ?? null,
            identityFile: $config['identityFile'] ?? null,
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

        return $this->runShellCommand($sshCommand);
    }

    public function sshWithOutput(string|array $commands, Closure $onOutput): SshResult
    {
        $commandString = is_array($commands) ? implode(PHP_EOL, $commands) : $commands;

        if (static::isWindows()) {
            return $this->executeWindowsSsh($commandString, $onOutput);
        }

        $sshCommand = $this->buildSshCommandString($commandString);

        return $this->runShellCommand($sshCommand, onOutput: $onOutput);
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
            return $this->executeProcess(new Process($this->buildWindowsScpArgs($localPath, "{$this->getTarget()}:{$remotePath}")));
        }

        return $this->runShellCommand($this->buildScpCommandString($localPath, "{$this->getTarget()}:{$remotePath}"));
    }

    public function download(string $remotePath, string $localPath): SshResult
    {
        if (static::isWindows()) {
            return $this->executeProcess(new Process($this->buildWindowsScpArgs("{$this->getTarget()}:{$remotePath}", $localPath)));
        }

        return $this->runShellCommand($this->buildScpCommandString("{$this->getTarget()}:{$remotePath}", $localPath));
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

        $process = new Process([
            'ssh', '-O', 'exit',
            '-o', 'ControlPath='.escapeshellarg($socketPath),
            $this->getTarget(),
        ]);
        $process->setTimeout(10);
        $process->run();
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
                throw SSHConnectionException::connectionFailed(
                    $this->host,
                    $this->user,
                    "Identity file not found: {$this->identityFile}"
                );
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
            $options[] = '-i '.escapeshellarg($this->identityFile);
        }

        if (! $this->strictHostKeyChecking) {
            $options[] = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
        }

        $options[] = '-o PasswordAuthentication=no';

        if ($this->multiplexingEnabled && $this->controlPath) {
            $options[] = '-o ControlMaster=auto -o ControlPath='.escapeshellarg($this->controlPath).' -o ControlPersist=60';
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
            $parts[] = '-i '.escapeshellarg($this->identityFile);
        }

        if (! $this->strictHostKeyChecking) {
            $parts[] = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
        }

        if ($this->multiplexingEnabled && $this->controlPath) {
            $parts[] = '-o ControlMaster=auto -o ControlPersist=60 -o ControlPath='.escapeshellarg($this->controlPath);
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

    public static function wrapForWsl(string $command, array $windowsPaths = []): string
    {
        if (! static::isWindows()) {
            return $command;
        }

        foreach ($windowsPaths as $winPath) {
            $command = str_replace($winPath, static::windowsPathToWsl($winPath), $command);
        }

        return 'wsl '.$command;
    }

    // ============================================================
    // Private: Command Building
    // ============================================================

    private function buildSshCommandString(string $commandString): string
    {
        $options = $this->buildSshOptionsString();
        $target = $this->getTarget();
        $delimiter = 'EOF_DEPLOYER_'.bin2hex(random_bytes(4));

        return "ssh {$options} {$target} 'bash -se' << \\{$delimiter}".PHP_EOL
            .$commandString.PHP_EOL
            .$delimiter;
    }

    private function buildScpCommandString(string $source, string $destination): string
    {
        $options = $this->buildScpOptionsString();

        return "scp {$options} ".escapeshellarg($source).' '.escapeshellarg($destination);
    }

    private function buildScpOptionsString(): string
    {
        $options = ['-r'];

        if ($this->port !== null) {
            $options[] = "-P {$this->port}";
        }

        if ($this->identityFile) {
            $options[] = '-i '.escapeshellarg($this->identityFile);
        }

        if (! $this->strictHostKeyChecking) {
            $options[] = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
        }

        $options[] = '-o PasswordAuthentication=no';

        return implode(' ', $options);
    }

    // ============================================================
    // Private: Windows Execution
    // ============================================================

    private function executeWindowsSsh(string $commandString, ?Closure $onOutput = null): SshResult
    {
        $sshBinary = 'C:\\Windows\\System32\\OpenSSH\\ssh.exe';
        $args = array_merge([$sshBinary], $this->buildSshOptions(), [$this->getTarget(), $commandString]);

        return $this->executeProcess(new Process($args), onOutput: $onOutput);
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

    // ============================================================
    // Private: Process Execution
    // ============================================================

    private function runShellCommand(string $command, ?int $timeout = null, ?Closure $onOutput = null): SshResult
    {
        return $this->executeProcess(
            Process::fromShellCommandline($command),
            timeout: $timeout,
            onOutput: $onOutput,
        );
    }

    private function executeProcess(Process $process, ?int $timeout = null, ?Closure $onOutput = null): SshResult
    {
        $process->setTimeout($timeout ?? $this->timeout);
        $process->run($onOutput);

        return new SshResult(
            successful: $process->isSuccessful(),
            exitCode: $process->getExitCode() ?? -1,
            output: trim($process->getOutput()),
            errorOutput: trim($process->getErrorOutput()),
        );
    }
}
