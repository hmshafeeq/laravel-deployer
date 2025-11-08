<?php

namespace Shaf\LaravelDeployer\Actions\Artisan;

use Shaf\LaravelDeployer\Actions\AbstractAction;

abstract class AbstractArtisanAction extends AbstractAction
{
    protected function getPhpPath(): string
    {
        return "/usr/bin/php";
    }

    protected function runArtisan(string $command): string
    {
        $releasePath = $this->deployer->getReleasePath();
        $phpPath = $this->getPhpPath();

        $fullCommand = "{$phpPath} {$releasePath}/artisan {$command}";
        $this->deployer->writeln("run {$fullCommand}");

        $result = $this->deployer->run($fullCommand);

        if (!empty($result)) {
            $lines = explode("\n", trim($result));
            foreach ($lines as $line) {
                $this->deployer->writeln($line);
            }
        }

        return $result;
    }
}
