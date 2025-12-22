<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Support\Abstract\DeploymentAction;

/**
 * Run post-deployment scripts and tasks
 *
 * This action handles post-deployment tasks such as publishing vendor assets
 * and running custom post-deployment scripts.
 */
class RunPostDeploymentScriptsAction extends DeploymentAction
{
    /**
     * Create a new RunPostDeploymentScriptsAction instance
     */
    public function __construct(
        protected Deployer $deployer
    ) {
        parent::__construct($deployer);
    }

    /**
     * Execute post-deployment scripts
     */
    public function execute(): void
    {
        $currentPath = $this->getCurrentPath();
        $phpPath = config('laravel-deployer.php.executable', 'php');

        $this->publishLogViewerAssets($currentPath, $phpPath);
        $this->runCustomPostDeploymentScript($currentPath);
    }

    /**
     * Publish log viewer assets
     */
    protected function publishLogViewerAssets(string $currentPath, string $phpPath): void
    {
        $command = "cd {$currentPath} && {$phpPath} artisan vendor:publish --tag=log-viewer-assets --force";

        $this->writeln("run {$command}");

        try {
            $result = $this->cmd($command);

            if (! empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $this->writeln($line);
                }
            }
        } catch (\Exception $e) {
            $this->writeln('⚠ Failed to publish log viewer assets', 'comment');
        }
    }

    /**
     * Run custom post-deployment script
     */
    protected function runCustomPostDeploymentScript(string $currentPath): void
    {
        $scriptPath = "{$currentPath}/post-deployment.sh";

        // Check if script exists
        $exists = $this->deployer->test("[ -f {$scriptPath} ]");

        if (! $exists) {
            return;
        }

        $command = "cd {$currentPath} && ./post-deployment.sh";
        $this->writeln("run {$command}");

        try {
            $result = $this->cmd($command);

            if (! empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $this->writeln($line);
                }
            }
        } catch (\Exception $e) {
            $this->writeln('⚠ Post-deployment script failed', 'comment');
        }
    }
}
