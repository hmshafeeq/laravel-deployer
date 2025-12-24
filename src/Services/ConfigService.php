<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Exceptions\ConfigurationException;

/**
 * Service for loading and managing deployment configuration.
 * Uses JSON config (.deploy/deploy.json) + .env secrets.
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
        $configPath = $this->findConfigFile();
        $config = $this->parseJson($configPath);

        $this->validateEnvironment($environment, $config);

        // Deep merge global config with environment-specific config
        $envConfig = $config['environments'][$environment] ?? [];
        $mergedConfig = $this->deepMerge($config, $envConfig);

        // Load secrets from .env file
        $this->loadEnvFile($environment);
        $mergedConfig = $this->applyEnvSecrets($mergedConfig);

        return DeploymentConfig::fromArray($environment, $mergedConfig);
    }

    /**
     * Get list of available environments
     */
    public function getAvailableEnvironments(): array
    {
        try {
            $configPath = $this->findConfigFile();
            $config = $this->parseJson($configPath);

            return array_keys($config['environments'] ?? []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Find the configuration file
     */
    private function findConfigFile(): string
    {
        $path = $this->basePath.'/.deploy/deploy.json';

        if (file_exists($path)) {
            return $path;
        }

        throw ConfigurationException::fileNotFound('deploy.json (expected at .deploy/deploy.json)');
    }

    /**
     * Parse JSON config file
     */
    private function parseJson(string $path): array
    {
        $contents = file_get_contents($path);
        $config = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ConfigurationException::invalidJson($path, json_last_error_msg());
        }

        return $config;
    }

    /**
     * Validate environment exists in configuration
     */
    private function validateEnvironment(string $environment, array $config): void
    {
        if (! isset($config['environments'][$environment])) {
            $available = array_keys($config['environments'] ?? []);
            throw ConfigurationException::environmentNotFound($environment, $available);
        }
    }

    /**
     * Deep merge two arrays (environment config extends global config)
     */
    private function deepMerge(array $base, array $override): array
    {
        $result = $base;

        foreach ($override as $key => $value) {
            // Skip 'environments' key - we don't want to nest it
            if ($key === 'environments') {
                continue;
            }

            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                // If both are indexed arrays, replace entirely
                if ($this->isIndexedArray($value) && $this->isIndexedArray($result[$key])) {
                    $result[$key] = $value;
                } else {
                    // Associative arrays: deep merge
                    $result[$key] = $this->deepMerge($result[$key], $value);
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if array is indexed (sequential numeric keys)
     */
    private function isIndexedArray(array $array): bool
    {
        if (empty($array)) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Load environment-specific .env file for secrets
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
     * Apply secrets from environment variables
     */
    private function applyEnvSecrets(array $config): array
    {
        if ($host = $this->getEnv('DEPLOY_HOST')) {
            $config['hostname'] = $host;
        }

        if ($user = $this->getEnv('DEPLOY_USER')) {
            $config['remoteUser'] = $user;
        }

        if ($path = $this->getEnv('DEPLOY_PATH')) {
            $config['deployPath'] = $path;
        }

        if ($identityFile = $this->getEnv('DEPLOY_IDENTITY_FILE')) {
            $config['identityFile'] = $identityFile;
        }

        if ($port = $this->getEnv('DEPLOY_PORT')) {
            $config['port'] = (int) $port;
        }

        if ($githubToken = $this->getEnv('GITHUB_TOKEN')) {
            $config['githubToken'] = $githubToken;
        }

        return $config;
    }

    /**
     * Get environment variable
     */
    private function getEnv(string $key): ?string
    {
        return $_ENV[$key] ?? getenv($key) ?: null;
    }
}
