<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

class WritableAction extends AbstractDeploymentAction
{
    public function execute(): void
    {
        $releasePath = $this->deployer->getReleasePath();

        // Check if release symlink exists
        $this->deployer->writeln("run if [ -h {$this->deployer->getDeployPath()}/release ]; then echo +correct; fi");
        $result = $this->deployer->run("if [ -h {$this->deployer->getDeployPath()}/release ]; then echo +correct; fi");
        if (!empty($result)) {
            $this->deployer->writeln($result);
        }

        // Create writable directories
        $dirs = [
            'bootstrap/cache',
            'storage',
            'storage/app',
            'storage/app/public',
            'storage/framework',
            'storage/framework/cache',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs'
        ];

        $dirList = implode(' ', $dirs);
        $this->deployer->writeln("run cd {$releasePath} && (mkdir -p {$dirList})");
        $this->deployer->run("cd {$releasePath} && (mkdir -p {$dirList})");

        // Detect web server user
        $this->deployer->writeln("run cd {$releasePath} && (ps axo comm,user | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | sort | awk '{print \$NF}' | uniq)");
        $httpUser = $this->deployer->run("cd {$releasePath} && (ps axo comm,user | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | sort | awk '{print \$NF}' | uniq)");
        if (!empty($httpUser)) {
            $this->deployer->writeln($httpUser);
        }

        // Check if chmod supports specific format
        $this->deployer->writeln("run cd {$releasePath} && (chmod 2>&1; true)");
        $chmodResult = $this->deployer->run("cd {$releasePath} && (chmod 2>&1; true)");
        if (!empty($chmodResult)) {
            $lines = explode("\n", trim($chmodResult));
            foreach ($lines as $line) {
                $this->deployer->writeln($line);
            }
        }

        // Check if setfacl is available
        $this->deployer->writeln("run cd {$releasePath} && (if hash setfacl 2>/dev/null; then echo +true; fi)");
        $hasSetfacl = $this->deployer->run("cd {$releasePath} && (if hash setfacl 2>/dev/null; then echo +true; fi)");
        if (!empty($hasSetfacl)) {
            $this->deployer->writeln($hasSetfacl);

            // Get current user
            $currentUser = $this->deployer->get('remote_user');
            $this->deployer->writeln("run cd {$releasePath} && (if id -u {$currentUser} &>/dev/null 2>&1 || exit 0; then echo +right; fi)");
            $userExists = $this->deployer->run("cd {$releasePath} && (if id -u {$currentUser} &>/dev/null 2>&1 || exit 0; then echo +right; fi)");
            if (!empty($userExists)) {
                $this->deployer->writeln($userExists);
            }

            // Set ACLs for bootstrap/cache
            $this->deployer->writeln("run cd {$releasePath} && (getfacl -p bootstrap/cache | grep \"^user:{$httpUser}:.*w\" | wc -l)");
            $aclCount = $this->deployer->run("cd {$releasePath} && (getfacl -p bootstrap/cache | grep \"^user:{$httpUser}:.*w\" | wc -l)");
            if (!empty($aclCount) && trim($aclCount) !== '0') {
                $this->deployer->writeln($aclCount);
            } else {
                $this->deployer->writeln("run cd {$releasePath} && (setfacl -L  -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX bootstrap/cache)");
                $this->deployer->run("cd {$releasePath} && (setfacl -L  -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX bootstrap/cache)");

                $this->deployer->writeln("run cd {$releasePath} && (setfacl -dL  -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX bootstrap/cache)");
                $this->deployer->run("cd {$releasePath} && (setfacl -dL  -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX bootstrap/cache)");
            }

            // Check ACLs for storage directories
            foreach (['storage', 'storage/app', 'storage/app/public', 'storage/framework', 'storage/framework/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $dir) {
                $this->deployer->writeln("run cd {$releasePath} && (getfacl -p {$dir} | grep \"^user:{$httpUser}:.*w\" | wc -l)");
                $aclCount = $this->deployer->run("cd {$releasePath} && (getfacl -p {$dir} | grep \"^user:{$httpUser}:.*w\" | wc -l)");
                if (!empty($aclCount)) {
                    $this->deployer->writeln($aclCount);
                }
            }
        }
    }

    public function getName(): string
    {
        return 'deploy:writable';
    }
}
