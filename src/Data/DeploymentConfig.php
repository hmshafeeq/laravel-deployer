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
        public string $branch,
        public string $composerOptions,
        public int $keepReleases = 3,
        public bool $isLocal = false,
        public string $application = 'Application',
        public array $rsyncExcludes = [],
        public array $rsyncIncludes = [],
        public ?int $port = null,
        public ?string $identityFile = null,
        public bool $showDiff = true,
        public bool $confirmChanges = true,
        public bool $showUploadProgress = true,
        public int $diffDisplayLimit = 20,
        public string $phpBinary = 'php',
        public ?string $githubToken = null,
    ) {}

    public static function fromArray(string $environment, array $config, array $globalConfig = []): self
    {
        return new self(
            environment: Environment::fromString($environment),
            hostname: $config['hostname'] ?? 'localhost',
            remoteUser: $config['remote_user'] ?? 'deploy',
            deployPath: $config['deploy_path'] ?? '/var/www/app',
            branch: $config['branch'] ?? 'main',
            composerOptions: $config['composer_options'] ?? '--verbose --prefer-dist --no-interaction --no-scripts --optimize-autoloader',
            keepReleases: $globalConfig['keep_releases'] ?? $config['keep_releases'] ?? 3,
            isLocal: $config['local'] ?? false,
            application: $globalConfig['application'] ?? $config['application'] ?? 'Application',
            rsyncExcludes: $globalConfig['rsync']['exclude'] ?? [],
            rsyncIncludes: $globalConfig['rsync']['include'] ?? [],
            port: $config['port'] ?? null,
            identityFile: $config['identity_file'] ?? null,
            showDiff: $globalConfig['show_diff'] ?? $config['show_diff'] ?? true,
            confirmChanges: $globalConfig['confirm_changes'] ?? $config['confirm_changes'] ?? true,
            showUploadProgress: $globalConfig['show_upload_progress'] ?? $config['show_upload_progress'] ?? true,
            diffDisplayLimit: $globalConfig['diff_display_limit'] ?? $config['diff_display_limit'] ?? 20,
            phpBinary: $config['bin/php'] ?? $config['php_binary'] ?? 'php',
            githubToken: $config['github_token'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'environment' => $this->environment->value,
            'hostname' => $this->hostname,
            'remote_user' => $this->remoteUser,
            'deploy_path' => $this->deployPath,
            'branch' => $this->branch,
            'composer_options' => $this->composerOptions,
            'keep_releases' => $this->keepReleases,
            'local' => $this->isLocal,
            'application' => $this->application,
            'port' => $this->port,
        ];
    }
}
