<?php

namespace Shaf\LaravelDeployer\Data;

use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Services\SshService;

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
        public string $rsyncFlags = 'rz',
        public ?string $identityFile = null,
        public ?int $port = null,
        public bool $showDiff = true,
        public bool $showPreview = true,
        public bool $confirmChanges = true,
        public bool $showUploadProgress = true,
        public int $diffDisplayLimit = 20,
        public string $phpBinary = 'php',
        public array $postDeployCommands = [],
        public array $beforeSymlink = [],
        public array $afterSymlink = [],
        public string $branch = 'main',
        public ?string $githubToken = null,
        public bool $strictHostKeyChecking = true,
        public bool $assetsFailOnError = true,
        /** @var array<string> Paths to verify exist on server after file sync (optional) */
        public array $assetsVerify = [],
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
        // Copy vendor/ from previous release (saves ~40s on composer install)
        public bool $copyVendor = true,
        // Service restart configuration
        /** @var array<string> Services that MUST restart successfully (deployment fails if they don't) */
        public array $requiredServices = ['php-fpm', 'nginx'],
        /** @var array<string> Services that are optional (warn on failure but continue) */
        public array $optionalServices = ['supervisor'],
        /** Whether to automatically merge .gitignore patterns into rsync excludes */
        public bool $useGitignore = true,
        /** Skip frontend asset building (interactive mode override) */
        public bool $skipAssetBuild = false,
        /** Skip database migrations (interactive mode override) */
        public bool $skipMigrations = false,
    ) {}

    /**
     * Create a new instance with specific property overrides.
     * Provides a clean way to modify readonly config without verbose recreation.
     *
     * @param  array<string, mixed>  $overrides  Property name => value pairs to override
     *
     * @example $config->with(['showDiff' => false, 'confirmChanges' => false])
     */
    public function with(array $overrides): self
    {
        return new self(
            environment: $overrides['environment'] ?? $this->environment,
            hostname: $overrides['hostname'] ?? $this->hostname,
            remoteUser: $overrides['remoteUser'] ?? $this->remoteUser,
            deployPath: $overrides['deployPath'] ?? $this->deployPath,
            composerOptions: $overrides['composerOptions'] ?? $this->composerOptions,
            keepReleases: $overrides['keepReleases'] ?? $this->keepReleases,
            isLocal: $overrides['isLocal'] ?? $this->isLocal,
            rsyncExcludes: $overrides['rsyncExcludes'] ?? $this->rsyncExcludes,
            rsyncIncludes: $overrides['rsyncIncludes'] ?? $this->rsyncIncludes,
            rsyncOptions: $overrides['rsyncOptions'] ?? $this->rsyncOptions,
            rsyncFlags: $overrides['rsyncFlags'] ?? $this->rsyncFlags,
            identityFile: $overrides['identityFile'] ?? $this->identityFile,
            port: $overrides['port'] ?? $this->port,
            showDiff: $overrides['showDiff'] ?? $this->showDiff,
            showPreview: $overrides['showPreview'] ?? $this->showPreview,
            confirmChanges: $overrides['confirmChanges'] ?? $this->confirmChanges,
            showUploadProgress: $overrides['showUploadProgress'] ?? $this->showUploadProgress,
            diffDisplayLimit: $overrides['diffDisplayLimit'] ?? $this->diffDisplayLimit,
            phpBinary: $overrides['phpBinary'] ?? $this->phpBinary,
            postDeployCommands: $overrides['postDeployCommands'] ?? $this->postDeployCommands,
            beforeSymlink: $overrides['beforeSymlink'] ?? $this->beforeSymlink,
            afterSymlink: $overrides['afterSymlink'] ?? $this->afterSymlink,
            branch: $overrides['branch'] ?? $this->branch,
            githubToken: $overrides['githubToken'] ?? $this->githubToken,
            strictHostKeyChecking: $overrides['strictHostKeyChecking'] ?? $this->strictHostKeyChecking,
            assetsFailOnError: $overrides['assetsFailOnError'] ?? $this->assetsFailOnError,
            assetsVerify: $overrides['assetsVerify'] ?? $this->assetsVerify,
            healthCheckUrl: $overrides['healthCheckUrl'] ?? $this->healthCheckUrl,
            maintenanceMode: $overrides['maintenanceMode'] ?? $this->maintenanceMode,
            maintenanceSecret: $overrides['maintenanceSecret'] ?? $this->maintenanceSecret,
            backupBeforeMigrate: $overrides['backupBeforeMigrate'] ?? $this->backupBeforeMigrate,
            hooks: $overrides['hooks'] ?? $this->hooks,
            skipPermissionFix: $overrides['skipPermissionFix'] ?? $this->skipPermissionFix,
            webGroup: $overrides['webGroup'] ?? $this->webGroup,
            enforceSetgid: $overrides['enforceSetgid'] ?? $this->enforceSetgid,
            directoryMode: $overrides['directoryMode'] ?? $this->directoryMode,
            fileMode: $overrides['fileMode'] ?? $this->fileMode,
            copyVendor: $overrides['copyVendor'] ?? $this->copyVendor,
            requiredServices: $overrides['requiredServices'] ?? $this->requiredServices,
            optionalServices: $overrides['optionalServices'] ?? $this->optionalServices,
            useGitignore: $overrides['useGitignore'] ?? $this->useGitignore,
            skipAssetBuild: $overrides['skipAssetBuild'] ?? $this->skipAssetBuild,
            skipMigrations: $overrides['skipMigrations'] ?? $this->skipMigrations,
        );
    }

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
            rsyncFlags: $rsync['flags'] ?? 'rz',
            identityFile: $config['identityFile'] ?? null,
            port: $config['port'] ?? null,
            showDiff: $display['showDiff'] ?? $config['showDiff'] ?? true,
            showPreview: $display['showPreview'] ?? $config['showPreview'] ?? true,
            confirmChanges: $display['confirmChanges'] ?? $config['confirmChanges'] ?? true,
            showUploadProgress: $display['showUploadProgress'] ?? $config['showUploadProgress'] ?? true,
            diffDisplayLimit: $display['diffDisplayLimit'] ?? $config['diffDisplayLimit'] ?? 20,
            phpBinary: $config['phpBinary'] ?? 'php',
            postDeployCommands: array_merge(
                $config['afterSymlink'] ?? [],
                $config['postDeploy'] ?? []
            ),
            beforeSymlink: $config['beforeSymlink'] ?? [],
            afterSymlink: array_merge(
                $config['afterSymlink'] ?? [],
                $config['postDeploy'] ?? []
            ),
            branch: $config['branch'] ?? self::detectCurrentBranch(),
            githubToken: $config['githubToken'] ?? null,
            strictHostKeyChecking: $ssh['strictHostKeyChecking'] ?? true,
            assetsFailOnError: $assets['failOnError'] ?? true,
            assetsVerify: $assets['verify'] ?? [],
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
            copyVendor: $config['copyVendor'] ?? true,
            requiredServices: $config['requiredServices'] ?? ['php-fpm', 'nginx'],
            optionalServices: $config['optionalServices'] ?? ['supervisor'],
            useGitignore: $rsync['useGitignore'] ?? true,
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
        $branch = trim((string) shell_exec('git rev-parse --abbrev-ref HEAD '.SshService::suppressStderr()));

        return $branch ?: 'main';
    }
}
