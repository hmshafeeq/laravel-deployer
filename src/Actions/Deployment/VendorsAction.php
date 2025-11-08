<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class VendorsAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $releasePath = $this->deployer->getReleasePath();
        $composerOptions = $this->deployer->get('composer_options', '--verbose --prefer-dist --no-interaction --no-scripts --optimize-autoloader');

        // Check if unzip is available
        $this->deployer->writeln("run if hash unzip 2>/dev/null; then echo +accurate; fi");
        $hasUnzip = $this->deployer->run("if hash unzip 2>/dev/null; then echo +accurate; fi");
        if (!empty($hasUnzip)) {
            $this->deployer->writeln($hasUnzip);
        }

        // Check for composer.phar
        $this->deployer->writeln("run if [ -f {$this->deployer->getDeployPath()}/.dep/composer.phar ]; then echo +true; fi");
        $this->deployer->run("if [ -f {$this->deployer->getDeployPath()}/.dep/composer.phar ]; then echo +true; fi");

        // Check if composer command is available
        $this->deployer->writeln("run if hash composer 2>/dev/null; then echo +indeed; fi");
        $hasComposer = $this->deployer->run("if hash composer 2>/dev/null; then echo +indeed; fi");
        if (!empty($hasComposer)) {
            $this->deployer->writeln($hasComposer);
        }

        // Get composer path
        $this->deployer->writeln("run command -v 'composer' || which 'composer' || type -p 'composer'");
        $composerPath = $this->deployer->run("command -v 'composer' || which 'composer' || type -p 'composer'");
        $this->deployer->writeln($composerPath);

        // Get PHP path
        $this->deployer->writeln("run command -v 'php' || which 'php' || type -p 'php'");
        $phpPath = $this->deployer->run("command -v 'php' || which 'php' || type -p 'php'");
        $this->deployer->writeln($phpPath);

        // Run composer install
        $composerCommand = "cd {$releasePath} && {$phpPath} {$composerPath} install {$composerOptions} 2>&1";
        $this->deployer->writeln("run {$composerCommand}");

        $result = $this->deployer->run($composerCommand);
        if (!empty($result)) {
            $lines = explode("\n", $result);
            foreach ($lines as $line) {
                $this->deployer->writeln($line);
            }
        }
    }

    public function getName(): string
    {
        return 'deploy:vendors';
    }
}
