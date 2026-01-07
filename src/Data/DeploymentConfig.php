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
        public bool $showPreview = true,
        public bool $confirmChanges = true,
        public bool $showUploadProgress = true,
        public int $diffDisplayLimit = 20,
        public string $phpBinary = 'php',
        public array $postDeployCommands = [],
        public string $branch = 'main',
        public ?string $githubToken = null,
        public bool $strictHostKeyChecking = true,
        public bool $assetsFailOnError = true,
        // Health check configuration (simplified: URL presence = enabled)
        public ?string $healthCheckUrl = null,
        // Maintenance mode configuration
        public bool $maintenanceMode = false,
        public ?string $maintenanceSecret = null,
        // Pre-migration backup
        public bool $backupBeforeMigrate = false,
        // Hooks configuration
        public array $hooks = [],
        // Skip permission fix (useful when server umask is correctly configured)
        public bool $skipPermissionFix = false,
        // Permission settings
        public string $webGroup = 'www-data',
        public bool $enforceSetgid = true,
        public string $directoryMode = '2775',
        public string $fileMode = '664',
    ) {}

    /**
     * Get the SSH user (alias for remoteUser for clarity)
     */
    public function getSshUser(): string
    {
        return $this->remoteUser;
    }

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
            showPreview: $display['showPreview'] ?? $config['showPreview'] ?? true,
            confirmChanges: $display['confirmChanges'] ?? $config['confirmChanges'] ?? true,
            showUploadProgress: $display['showUploadProgress'] ?? $config['showUploadProgress'] ?? true,
            diffDisplayLimit: $display['diffDisplayLimit'] ?? $config['diffDisplayLimit'] ?? 20,
            phpBinary: $config['phpBinary'] ?? 'php',
            postDeployCommands: $config['postDeploy'] ?? [],
            branch: $config['branch'] ?? self::detectCurrentBranch(),
            githubToken: $config['githubToken'] ?? null,
            strictHostKeyChecking: $ssh['strictHostKeyChecking'] ?? true,
            assetsFailOnError: $assets['failOnError'] ?? true,
            healthCheckUrl: $config['healthCheckUrl'] ?? null,
            maintenanceMode: $config['maintenanceMode'] ?? false,
            maintenanceSecret: $config['maintenanceSecret'] ?? null,
            backupBeforeMigrate: $config['backupBeforeMigrate'] ?? false,
            hooks: $config['hooks'] ?? [],
            skipPermissionFix: $config['skipPermissionFix'] ?? false,
            webGroup: $config['permissions']['webGroup'] ?? $config['webGroup'] ?? 'www-data',
            enforceSetgid: $config['permissions']['enforceSetgid'] ?? $config['enforceSetgid'] ?? true,
            directoryMode: $config['permissions']['directoryMode'] ?? $config['directoryMode'] ?? '2775',
            fileMode: $config['permissions']['fileMode'] ?? $config['fileMode'] ?? '664',
        );
    }

    /**
     * Check if health check is enabled (URL is set)
     */
    public function isHealthCheckEnabled(): bool
    {
        return ! empty($this->healthCheckUrl);
    }

    /**
     * Detect current git branch for release logging
     */
    private static function detectCurrentBranch(): string
    {
        $branch = trim((string) shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null'));

        return $branch ?: 'main';
    }
}
