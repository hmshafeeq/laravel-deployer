<?php

namespace Shaf\LaravelDeployer\Data;

use Shaf\LaravelDeployer\Enums\Environment;

readonly class DeploymentConfig
{
    public function __construct(
        public Environment $environment,
        public string $hostname,
        public string $remoteUser,
        public string $deployPath,
        public string $composerOptions,
        public int $keepReleases = 3,
        public bool $isLocal = false,
        public array $rsyncExcludes = [],
        public array $rsyncIncludes = [],
        public array $rsyncOptions = [],
        public string $rsyncFlags = 'rzc',
        public ?string $identityFile = null,
        public ?int $port = null,
        public bool $showDiff = true,
        public bool $confirmChanges = true,
        public bool $showUploadProgress = true,
        public int $diffDisplayLimit = 20,
        public string $phpBinary = 'php',
        public array $postDeployCommands = [],
        public string $branch = 'main',
        public ?string $githubToken = null,
        public bool $strictHostKeyChecking = true,
        public bool $assetsFailOnError = true,
    ) {}

    public static function fromArray(string $environment, array $config): self
    {
        // Extract nested config sections
        $display = $config['display'] ?? [];
        $rsync = $config['rsync'] ?? [];
        $composer = $config['composer'] ?? [];

        $ssh = $config['ssh'] ?? [];
        $assets = $config['assets'] ?? [];

        return new self(
            environment: Environment::fromString($environment),
            hostname: $config['hostname'] ?? 'localhost',
            remoteUser: $config['remoteUser'] ?? 'deploy',
            deployPath: $config['deployPath'] ?? '/var/www/app',
            composerOptions: $composer['options'] ?? $config['composerOptions'] ?? '--prefer-dist --no-interaction --optimize-autoloader',
            keepReleases: $config['keepReleases'] ?? 3,
            isLocal: $config['local'] ?? false,
            rsyncExcludes: $rsync['exclude'] ?? [],
            rsyncIncludes: $rsync['include'] ?? [],
            rsyncOptions: $rsync['options'] ?? ['delete', 'delete-after', 'compress'],
            rsyncFlags: $rsync['flags'] ?? 'rzc',
            identityFile: $config['identityFile'] ?? null,
            port: $config['port'] ?? null,
            showDiff: $display['showDiff'] ?? $config['showDiff'] ?? true,
            confirmChanges: $display['confirmChanges'] ?? $config['confirmChanges'] ?? true,
            showUploadProgress: $display['showUploadProgress'] ?? $config['showUploadProgress'] ?? true,
            diffDisplayLimit: $display['diffDisplayLimit'] ?? $config['diffDisplayLimit'] ?? 20,
            phpBinary: $config['phpBinary'] ?? 'php',
            postDeployCommands: $config['postDeploy'] ?? [],
            branch: $config['branch'] ?? self::detectCurrentBranch(),
            githubToken: $config['githubToken'] ?? null,
            strictHostKeyChecking: $ssh['strictHostKeyChecking'] ?? true,
            assetsFailOnError: $assets['failOnError'] ?? true,
        );
    }

    /**
     * Detect current git branch for release logging
     */
    private static function detectCurrentBranch(): string
    {
        $branch = trim((string) shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null'));

        return $branch ?: 'main';
    }

    public function toArray(): array
    {
        return [
            'environment' => $this->environment->value,
            'hostname' => $this->hostname,
            'remoteUser' => $this->remoteUser,
            'deployPath' => $this->deployPath,
            'composerOptions' => $this->composerOptions,
            'keepReleases' => $this->keepReleases,
            'local' => $this->isLocal,
        ];
    }
}
