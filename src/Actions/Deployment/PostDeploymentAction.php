<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class PostDeploymentAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $currentPath = $this->deployer->getCurrentPath();

        // Publish log viewer assets
        $this->deployer->writeln("run cd {$currentPath} && /usr/bin/php artisan vendor:publish --tag=log-viewer-assets --force");
        $result = $this->deployer->run("cd {$currentPath} && /usr/bin/php artisan vendor:publish --tag=log-viewer-assets --force");
        if (!empty($result)) {
            $lines = explode("\n", trim($result));
            foreach ($lines as $line) {
                $this->deployer->writeln($line);
            }
        }

        // Run post-deployment script if it exists
        $this->deployer->writeln("run cd {$currentPath} && ./post-deployment.sh");
        $result = $this->deployer->run("cd {$currentPath} && ./post-deployment.sh");
        if (!empty($result)) {
            $lines = explode("\n", trim($result));
            foreach ($lines as $line) {
                $this->deployer->writeln($line);
            }
        }
    }

    public function getName(): string
    {
        return 'post:deployment';
    }
}
