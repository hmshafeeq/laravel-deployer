<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Deployer;
use Shaf\LaravelDeployer\Support\Abstract\DeploymentAction;

/**
 * Run post-deployment artisan commands
 *
 * This action executes artisan commands defined in the config
 * after all other deployment steps have finished.
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
     * Execute post-deployment commands
     */
    public function execute(): void
    {
        $commands = config('laravel-deployer.post_deploy_commands', []);

        if (empty($commands)) {
            return;
        }

        $currentPath = $this->getCurrentPath();
        $phpPath = config('laravel-deployer.php.executable', 'php');

        foreach ($commands as $artisanCommand) {
            $command = "cd {$currentPath} && {$phpPath} artisan {$artisanCommand}";

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
                $this->writeln("⚠ Failed to run: artisan {$artisanCommand}", 'comment');
            }
        }
    }
}
