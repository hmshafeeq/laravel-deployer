<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class ReleaseAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $deployPath = $this->deployer->getDeployPath();
        $releaseName = $this->deployer->getReleaseName();

        // Check if release symlink exists
        $this->deployer->writeln("run cd {$deployPath} && (if [ -h release ]; then echo +yes; fi)");
        $result = $this->deployer->run("cd {$deployPath} && (if [ -h release ]; then echo +yes; fi)");
        if (!empty($result)) {
            $this->deployer->writeln($result);
        }

        // Check if releases directory has content
        $this->deployer->writeln("run cd {$deployPath} && (if [ -d releases ] && [ \"$(ls -A releases)\" ]; then echo +true; fi)");
        $hasReleases = $this->deployer->run("cd {$deployPath} && (if [ -d releases ] && [ \"$(ls -A releases)\" ]; then echo +true; fi)");
        if (!empty($hasReleases)) {
            $this->deployer->writeln($hasReleases);

            // List existing releases
            $this->deployer->writeln("run cd {$deployPath} && (cd releases && ls -t -1 -d */)");
            $releases = $this->deployer->run("cd {$deployPath} && (cd releases && ls -t -1 -d */)");
            if (!empty($releases)) {
                $lines = explode("\n", trim($releases));
                foreach ($lines as $line) {
                    $this->deployer->writeln($line);
                }
            }
        }

        // Check if releases_log exists
        $this->deployer->writeln("run cd {$deployPath} && (if [ -f .dep/releases_log ]; then echo +true; fi)");
        $hasLog = $this->deployer->run("cd {$deployPath} && (if [ -f .dep/releases_log ]; then echo +true; fi)");
        if (!empty($hasLog)) {
            $this->deployer->writeln($hasLog);

            // Show last releases from log
            $this->deployer->writeln("run cd {$deployPath} && (tail -n 300 .dep/releases_log)");
            $log = $this->deployer->run("cd {$deployPath} && (tail -n 300 .dep/releases_log)");
            if (!empty($log)) {
                $lines = explode("\n", trim($log));
                foreach ($lines as $line) {
                    $this->deployer->writeln($line);
                }
            }
        }

        // Check if release directory exists
        $this->deployer->writeln("run cd {$deployPath} && (if [ -d releases/{$releaseName} ]; then echo +correct; fi)");
        $this->deployer->run("cd {$deployPath} && (if [ -d releases/{$releaseName} ]; then echo +correct; fi)");

        // Write to latest_release
        $this->deployer->writeln("run cd {$deployPath} && (echo {$releaseName} > .dep/latest_release)");
        $this->deployer->run("cd {$deployPath} && (echo {$releaseName} > .dep/latest_release)");

        // Log the release
        $timestamp = date('Y-m-d\TH:i:s+0000');
        $user = $this->deployer->runLocally('git config --get user.name');
        $branch = $this->deployer->get('branch', 'HEAD');
        $logEntry = json_encode([
            'created_at' => $timestamp,
            'release_name' => $releaseName,
            'user' => $user,
            'target' => $branch
        ]);

        $this->deployer->writeln("run cd {$deployPath} && (echo '{$logEntry}' >> .dep/releases_log)");
        $this->deployer->run("cd {$deployPath} && (echo '{$logEntry}' >> .dep/releases_log)");

        // Create release directory
        $this->deployer->writeln("run cd {$deployPath} && (mkdir -p releases/{$releaseName})");
        $this->deployer->run("cd {$deployPath} && (mkdir -p releases/{$releaseName})");

        // Check if ln supports --relative
        $this->deployer->writeln("run cd {$deployPath} && ((man ln 2>&1 || ln -h 2>&1 || ln --help 2>&1) | grep -- --relative || true)");
        $supportsRelative = $this->deployer->run("cd {$deployPath} && ((man ln 2>&1 || ln -h 2>&1 || ln --help 2>&1) | grep -- --relative || true)");
        if (!empty($supportsRelative)) {
            $this->deployer->writeln("       -r, --relative");
        }

        // Create release symlink
        $this->deployer->writeln("run cd {$deployPath} && (ln -nfs --relative releases/{$releaseName} {$deployPath}/release)");
        $this->deployer->run("cd {$deployPath} && (ln -nfs --relative releases/{$releaseName} {$deployPath}/release)");
    }

    public function getName(): string
    {
        return 'deploy:release';
    }
}
