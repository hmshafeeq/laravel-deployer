<?php

namespace Shaf\LaravelDeployer;

use Illuminate\Support\Facades\File;
use Spatie\Ssh\Ssh;
use Symfony\Component\Process\Process;

class Deployer
{
    protected string $environment;
    protected array $config;
    protected ?Ssh $ssh = null;
    protected array $output = [];
    protected array $rsyncExcludes = [];
    protected array $rsyncIncludes = [];
    protected string $releaseName;
    protected bool $isLocal = false;

    public function __construct(string $environment, array $config)
    {
        $this->environment = $environment;
        $this->config = $config;
        $this->isLocal = $config['local'] ?? false;

        if (!$this->isLocal) {
            $this->ssh = Ssh::create($config['remote_user'], $config['hostname'])
                ->disableStrictHostKeyChecking()
                ->disablePasswordAuthentication();

            if (isset($config['port'])) {
                $this->ssh->usePort($config['port']);
            }
        }
    }

    public function run(string $command, bool $local = false): string
    {
        if ($local || $this->isLocal) {
            return $this->runLocally($command);
        }

        $result = $this->ssh->execute($command);

        return trim($result->getOutput());
    }

    public function runLocally(string $command, bool $showOutput = false): string
    {
        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(config('laravel-deployer.php.timeout', 900));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Command failed: {$command}\n{$process->getErrorOutput()}");
        }

        return trim($process->getOutput());
    }

    public function test(string $condition): bool
    {
        if ($this->isLocal) {
            $process = Process::fromShellCommandline($condition, base_path());
            $process->run();
            return $process->isSuccessful();
        }

        $result = $this->ssh->execute($condition . ' && echo "true" || echo "false"');
        return trim($result) === 'true';
    }

    public function writeln(string $message, string $style = 'info'): void
    {
        $prefix = "[{$this->environment}]";

        // Get colors from config
        $colors = config('laravel-deployer.output.colors', [
            'info' => "\033[32m",
            'comment' => "\033[33m",
            'error' => "\033[31m",
            'plain' => "",
        ]);

        $color = $colors[$style] ?? $colors['info'];
        $reset = config('laravel-deployer.output.reset', "\033[0m");

        echo "{$prefix} {$color}{$message}{$reset}\n";
        $this->output[] = $message;
    }

    public function task(string $name, callable $callback): void
    {
        echo "\ntask {$name}\n";
        $callback($this);
    }

    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function loadEnvironment(): void
    {
        $projectRoot = base_path();
        $deployEnvFile = "{$projectRoot}/.deploy/.env.{$this->environment}";

        if (file_exists($deployEnvFile)) {
            $dotenv = \Dotenv\Dotenv::createImmutable("{$projectRoot}/.deploy", ".env.{$this->environment}");
            $dotenv->load();

            $this->writeln("✅ Loaded environment variables from .deploy/.env.{$this->environment}");

            // Override configuration with environment variables
            $envPrefix = config('laravel-deployer.env_prefix', 'DEPLOY_');

            if ($host = $_ENV[$envPrefix.'HOST'] ?? getenv($envPrefix.'HOST')) {
                $this->config['hostname'] = $host;
            }

            if ($user = $_ENV[$envPrefix.'USER'] ?? getenv($envPrefix.'USER')) {
                $this->config['remote_user'] = $user;
            }

            if ($path = $_ENV[$envPrefix.'PATH'] ?? getenv($envPrefix.'PATH')) {
                $this->config['deploy_path'] = $path;
            }

            if ($branch = $_ENV[$envPrefix.'BRANCH'] ?? getenv($envPrefix.'BRANCH')) {
                $this->config['branch'] = $branch;
            }

            // Recreate SSH connection with updated config
            if (!$this->isLocal) {
                $this->ssh = Ssh::create($this->config['remote_user'], $this->config['hostname'])
                    ->disableStrictHostKeyChecking()
                    ->disablePasswordAuthentication();
            }

            $this->writeln("✅ Configuration loaded for environment: {$this->environment}");
        } else {
            $this->writeln("⚠️  No .deploy/.env.{$this->environment} file found", 'comment');
        }
    }

    public function confirmDeployment(bool $skipConfirm = false): bool
    {
        if ($skipConfirm) {
            $this->writeln("⏭️  Skipping deployment confirmation (--no-confirm flag used)", 'comment');
            echo "\n";
            return true;
        }

        echo "\n";
        echo "\033[33m═══════════════════════════════════════════════════════════\033[0m\n";
        echo "\033[33m                 DEPLOYMENT CONFIRMATION\033[0m\n";
        echo "\033[33m═══════════════════════════════════════════════════════════\033[0m\n";
        echo "\n";
        echo "  \033[32mEnvironment:\033[0m  \033[36m{$this->environment}\033[0m\n";
        echo "  \033[32mServer:\033[0m       \033[36m{$this->config['hostname']}\033[0m\n";
        echo "  \033[32mUser:\033[0m         \033[36m{$this->config['remote_user']}\033[0m\n";
        echo "  \033[32mDeploy Path:\033[0m  \033[36m{$this->config['deploy_path']}\033[0m\n";
        echo "\n";

        if (strtolower($this->environment) === 'production' || strtolower($this->environment) === 'prod') {
            echo "\033[31m⚠️  WARNING: You are deploying to PRODUCTION!\033[0m\n";
            echo "\n";
        }

        echo "\033[33m═══════════════════════════════════════════════════════════\033[0m\n";
        echo "\n";

        echo "  Do you want to continue with this deployment? [Y/n] ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        $confirmed = trim(strtolower($line)) !== 'n';
        fclose($handle);

        if (!$confirmed) {
            echo "\n";
            echo "\033[33m🛑 Deployment cancelled by user\033[0m\n";
            echo "\n";
            return false;
        }

        echo "\n";
        echo "\033[32m✓ Deployment confirmed, proceeding...\033[0m\n";
        echo "\n";

        return true;
    }

    public function generateReleaseName(): string
    {
        $yearMonth = date('Ym');
        $counterDir = "{$this->config['deploy_path']}/.dep/release_counter";
        $counterFile = "{$counterDir}/{$yearMonth}.txt";

        // Ensure the folder exists
        $this->run("mkdir -p {$counterDir}");

        // Read counter or start from 0
        $count = $this->run("if [ -f {$counterFile} ]; then cat {$counterFile}; else echo 0; fi");
        $count = (int) $count + 1;

        // Save updated counter
        $this->run("echo {$count} > {$counterFile}");

        $this->releaseName = "{$yearMonth}.{$count}";

        return $this->releaseName;
    }

    public function getReleaseName(): string
    {
        return $this->releaseName;
    }

    public function getDeployPath(): string
    {
        return $this->config['deploy_path'];
    }

    public function getCurrentPath(): string
    {
        return $this->config['deploy_path'] . '/current';
    }

    public function getReleasePath(): string
    {
        return $this->config['deploy_path'] . '/releases/' . $this->releaseName;
    }

    public function getSharedPath(): string
    {
        return $this->config['deploy_path'] . '/shared';
    }

    public function setRsyncExcludes(array $excludes): void
    {
        $this->rsyncExcludes = $excludes;
    }

    public function setRsyncIncludes(array $includes): void
    {
        $this->rsyncIncludes = $includes;
    }

    public function runRsync(): void
    {
        $source = base_path() . '/';
        $destination = "{$this->config['remote_user']}@{$this->config['hostname']}:{$this->getReleasePath()}/";

        $excludeArgs = [];
        foreach ($this->rsyncExcludes as $exclude) {
            $excludeArgs[] = "--exclude='{$exclude}'";
        }

        $includeArgs = [];
        foreach ($this->rsyncIncludes as $include) {
            $includeArgs[] = "--include='{$include}'";
        }

        $rsyncFlags = config('laravel-deployer.rsync.flags', '-rzc --delete --delete-after --compress');
        $sshOptions = config('laravel-deployer.rsync.ssh_options', "-e 'ssh -A -o ControlMaster=auto -o ControlPersist=60'");

        $rsyncCommand = sprintf(
            "rsync %s %s %s %s '%s' '%s'",
            $rsyncFlags,
            $sshOptions,
            implode(' ', $includeArgs),
            implode(' ', $excludeArgs),
            $source,
            $destination
        );

        $this->writeln("run " . str_replace([base_path(), $this->config['remote_user'] . '@' . $this->config['hostname']], ['', ''], $rsyncCommand));

        $process = Process::fromShellCommandline($rsyncCommand, base_path());
        $process->setTimeout(config('laravel-deployer.rsync.timeout', 900));
        $process->run(function ($type, $buffer) {
            if (Process::ERR === $type) {
                echo $buffer;
            } else {
                // Only show non-directory messages
                $lines = explode("\n", $buffer);
                foreach ($lines as $line) {
                    if (!empty(trim($line))) {
                        $this->writeln($line);
                    }
                }
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Rsync failed: " . $process->getErrorOutput());
        }
    }

    public function runLocalCommand(string $command, bool $showOutput = true): string
    {
        $this->writeln("run {$command}");

        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(config('laravel-deployer.php.timeout', 900));

        $output = '';
        $process->run(function ($type, $buffer) use (&$output, $showOutput) {
            $output .= $buffer;
            if ($showOutput) {
                // Split by lines and output each line with prefix
                $lines = explode("\n", rtrim($buffer, "\n"));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $this->writeln($line);
                    }
                }
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Command failed: {$command}\n{$process->getErrorOutput()}");
        }

        return trim($output);
    }

    public function isLocal(): bool
    {
        return $this->isLocal;
    }
}
