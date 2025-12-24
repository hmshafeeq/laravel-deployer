<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

use Shaf\LaravelDeployer\Deployer;

/**
 * Base class for service management actions
 *
 * Provides common functionality for managing system services including
 * detection, validation, and error handling for service operations.
 */
abstract class ServiceAction extends Action
{
    protected ?string $lastError = null;

    public function __construct(
        protected Deployer $deployer
    ) {}

    /**
     * Check if a service is installed/available
     *
     * @param  string  $service  Service name
     */
    protected function serviceExists(string $service): bool
    {
        $result = $this->cmd("systemctl list-unit-files {$service}.service 2>/dev/null | grep -q {$service} && echo 'exists' || echo 'not_exists'");

        return trim($result) === 'exists';
    }

    /**
     * Check if a service is currently running
     *
     * @param  string  $service  Service name
     */
    protected function serviceIsRunning(string $service): bool
    {
        $result = $this->cmd("systemctl is-active {$service} 2>/dev/null || echo 'inactive'");

        return trim($result) === 'active';
    }

    /**
     * Run a service command with error handling
     *
     * @param  string  $service  Service name
     * @param  string  $action  Action to perform (restart, reload, stop, start)
     * @param  bool  $useSudo  Whether to use sudo
     * @return bool Success status
     */
    protected function runServiceCommand(string $service, string $action, bool $useSudo = true): bool
    {
        try {
            $sudo = $useSudo ? 'sudo ' : '';
            $this->cmd("{$sudo}systemctl {$action} {$service}");
            $this->lastError = null;

            return true;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    /**
     * Get the last error message from a failed service command
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get service status information
     *
     * @param  string  $service  Service name
     * @return array{active: bool, enabled: bool, pid: ?int}
     */
    protected function getServiceStatus(string $service): array
    {
        $active = $this->serviceIsRunning($service);

        $enabledCheck = $this->cmd("systemctl is-enabled {$service} 2>/dev/null || echo 'disabled'");
        $enabled = trim($enabledCheck) === 'enabled';

        $pid = null;
        if ($active) {
            $pidCheck = $this->cmd("systemctl show --property MainPID --value {$service} 2>/dev/null");
            $pid = (int) trim($pidCheck) ?: null;
        }

        return [
            'active' => $active,
            'enabled' => $enabled,
            'pid' => $pid,
        ];
    }

    /**
     * Verify service restarted successfully
     *
     * @param  string  $service  Service name
     * @param  int  $maxRetries  Maximum number of retries
     * @param  int  $retryDelay  Delay between retries in seconds
     */
    protected function verifyServiceRestart(string $service, int $maxRetries = 3, int $retryDelay = 2): bool
    {
        for ($i = 0; $i < $maxRetries; $i++) {
            if ($i > 0) {
                sleep($retryDelay);
            }

            if ($this->serviceIsRunning($service)) {
                return true;
            }
        }

        return false;
    }
}
