<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Deployer;

class SystemCommandDetector
{
    public function __construct(
        private Deployer $deployer
    ) {}

    /**
     * Get PHP executable path
     */
    public function getPhpPath(): string
    {
        $result = $this->deployer->run("command -v 'php' || which 'php' || type -p 'php'");
        $this->deployer->writeln("run command -v 'php' || which 'php' || type -p 'php'");
        $this->deployer->writeln($result);

        return trim($result) ?: config('laravel-deployer.php.executable', '/usr/bin/php');
    }

    /**
     * Get Composer executable path
     */
    public function getComposerPath(): string
    {
        $result = $this->deployer->run("command -v 'composer' || which 'composer' || type -p 'composer'");
        $this->deployer->writeln("run command -v 'composer' || which 'composer' || type -p 'composer'");
        $this->deployer->writeln($result);

        return trim($result);
    }

    /**
     * Detect web server user (for file permissions)
     */
    public function getWebServerUser(string $path): ?string
    {
        $command = "cd {$path} && (ps axo comm,user | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | sort | awk '{print \$NF}' | uniq)";

        $this->deployer->writeln("run {$command}");
        $result = $this->deployer->run($command);

        if (!empty($result)) {
            $this->deployer->writeln($result);
            return trim($result);
        }

        return null;
    }

    /**
     * Check if setfacl command is available
     */
    public function hasSetfacl(string $path): bool
    {
        $command = "cd {$path} && (if hash setfacl 2>/dev/null; then echo +true; fi)";
        $this->deployer->writeln("run {$command}");

        $result = $this->deployer->run($command);

        if (!empty($result)) {
            $this->deployer->writeln($result);
            return true;
        }

        return false;
    }

    /**
     * Check if unzip command is available
     */
    public function hasUnzip(): bool
    {
        $result = $this->deployer->run("if hash unzip 2>/dev/null; then echo +accurate; fi");
        $this->deployer->writeln("run if hash unzip 2>/dev/null; then echo +accurate; fi");

        if (!empty($result)) {
            $this->deployer->writeln($result);
            return true;
        }

        return false;
    }

    /**
     * Check if composer command is available
     */
    public function hasComposer(): bool
    {
        $result = $this->deployer->run("if hash composer 2>/dev/null; then echo +indeed; fi");
        $this->deployer->writeln("run if hash composer 2>/dev/null; then echo +indeed; fi");

        if (!empty($result)) {
            $this->deployer->writeln($result);
            return true;
        }

        return false;
    }
}
