<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Exceptions\ConfigurationException;
use Symfony\Component\Yaml\Yaml;

/**
 * Service for loading and managing deployment configuration.
 * Simplified version of ConfigurationService.
 */
class ConfigService
{
    public function __construct(
        private string $basePath
    ) {}

    /**
     * Static helper for easy configuration loading
     */
    public static function load(string $environment, string $basePath): DeploymentConfig
    {
        $service = new self($basePath);

        return $service->loadConfig($environment);
    }

    /**
     * Load deployment configuration for an environment
     */
    public function loadConfig(string $environment): DeploymentConfig
    {
        $yamlPath = $this->findConfigFile();
        $yaml = $this->parseYaml($yamlPath);

        $this->validateEnvironment($environment, $yaml);

        $hostConfig = $yaml['hosts'][$environment];
        $globalConfig = $yaml['config'] ?? [];

        // Merge with environment variables
        $mergedConfig = $this->mergeWithEnvVars($environment, $hostConfig, $globalConfig);

        return DeploymentConfig::fromArray($environment, $mergedConfig, $globalConfig);
    }

    /**
     * Get list of available environments
     */
    public function getAvailableEnvironments(): array
    {
        try {
            $yamlPath = $this->findConfigFile();
            $yaml = $this->parseYaml($yamlPath);

            return array_keys($yaml['hosts'] ?? []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Find the configuration file
     */
    private function findConfigFile(): string
    {
        $locations = [
            $this->basePath.'/.deploy/deploy.yaml',
            $this->basePath.'/deploy.yaml',
        ];

        foreach ($locations as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw ConfigurationException::fileNotFound(
            'deploy.yaml (tried: .deploy/deploy.yaml, deploy.yaml)'
        );
    }

    /**
     * Parse YAML file
     */
    private function parseYaml(string $path): array
    {
        try {
            return Yaml::parseFile($path);
        } catch (\Exception $e) {
            throw ConfigurationException::invalidYaml($path, $e->getMessage());
        }
    }

    /**
     * Validate environment exists in configuration
     */
    private function validateEnvironment(string $environment, array $yaml): void
    {
        if (! isset($yaml['hosts'][$environment])) {
            $available = array_keys($yaml['hosts'] ?? []);
            throw ConfigurationException::environmentNotFound($environment, $available);
        }
    }

    /**
     * Merge configuration with environment variables
     */
    private function mergeWithEnvVars(string $environment, array $hostConfig, array $globalConfig): array
    {
        $this->loadEnvFile($environment);

        $overrides = [];

        if ($host = $this->getEnv('DEPLOY_HOST')) {
            $overrides['hostname'] = $host;
        }

        if ($user = $this->getEnv('DEPLOY_USER')) {
            $overrides['remote_user'] = $user;
        }

        if ($path = $this->getEnv('DEPLOY_PATH')) {
            $overrides['deploy_path'] = $path;
        }

        if ($branch = $this->getEnv('DEPLOY_BRANCH')) {
            $overrides['branch'] = $branch;
        }

        return array_merge($hostConfig, $overrides);
    }

    /**
     * Load environment-specific .env file
     */
    private function loadEnvFile(string $environment): void
    {
        $envFile = "{$this->basePath}/.deploy/.env.{$environment}";

        if (file_exists($envFile)) {
            $dotenv = \Dotenv\Dotenv::createImmutable(
                "{$this->basePath}/.deploy",
                ".env.{$environment}"
            );
            $dotenv->load();
        }
    }

    /**
     * Get environment variable
     */
    private function getEnv(string $key): ?string
    {
        return $_ENV[$key] ?? getenv($key) ?: null;
    }
}
