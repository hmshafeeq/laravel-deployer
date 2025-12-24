<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Deployer;

class PermissionManager
{
    public function __construct(
        protected Deployer $deployer,
        protected SystemCommandDetector $systemDetector
    ) {}

    /**
     * Run a command with verbose logging
     */
    protected function run(string $command): string
    {
        $this->deployer->writeln("run {$command}");

        return $this->deployer->run($command);
    }

    /**
     * Create writable directories
     */
    public function createWritableDirectories(string $releasePath): void
    {
        $dirs = config('laravel-deployer.paths.writable_dirs', [
            'bootstrap/cache',
            'storage',
            'storage/app',
            'storage/app/public',
            'storage/framework',
            'storage/framework/cache',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
        ]);

        $dirList = implode(' ', $dirs);
        $this->run("cd {$releasePath} && (mkdir -p {$dirList})");
    }

    /**
     * Set ACL permissions for writable directories
     */
    public function setAclPermissions(string $releasePath): void
    {
        // Detect web server user
        $httpUser = $this->systemDetector->getWebServerUser($releasePath);

        // Check if setfacl is available
        if (! $this->systemDetector->hasSetfacl($releasePath)) {
            return;
        }

        if (! $httpUser) {
            return;
        }

        $currentUser = $this->deployer->get('remote_user');

        // Verify current user exists
        $userExists = $this->run("cd {$releasePath} && (if id -u {$currentUser} &>/dev/null 2>&1 || exit 0; then echo +right; fi)");
        if (! empty($userExists)) {
            $this->deployer->writeln($userExists);
        }

        // Set ACLs for bootstrap/cache
        $this->setDirectoryAcl($releasePath, 'bootstrap/cache', $httpUser, $currentUser);

        // Set ACLs for storage directories
        $dirs = config('laravel-deployer.paths.writable_dirs', []);
        foreach ($dirs as $dir) {
            if (str_starts_with($dir, 'storage')) {
                $this->checkDirectoryAcl($releasePath, $dir, $httpUser);
            }
        }
    }

    /**
     * Set ACL permissions for a specific directory
     */
    protected function setDirectoryAcl(string $releasePath, string $dir, string $httpUser, string $currentUser): void
    {
        $aclCount = $this->run("cd {$releasePath} && (getfacl -p {$dir} | grep \"^user:{$httpUser}:.*w\" | wc -l)");

        if (! empty($aclCount) && trim($aclCount) !== '0') {
            $this->deployer->writeln($aclCount);
        } else {
            $this->run("cd {$releasePath} && (setfacl -L  -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX {$dir})");
            $this->run("cd {$releasePath} && (setfacl -dL  -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX {$dir})");
        }
    }

    /**
     * Check ACL permissions for a specific directory
     */
    protected function checkDirectoryAcl(string $releasePath, string $dir, string $httpUser): void
    {
        $aclCount = $this->run("cd {$releasePath} && (getfacl -p {$dir} | grep \"^user:{$httpUser}:.*w\" | wc -l)");
        if (! empty($aclCount)) {
            $this->deployer->writeln($aclCount);
        }
    }

    /**
     * Fix module permissions (for modular structure)
     */
    public function fixModulePermissions(string $releasePath): void
    {
        $this->deployer->writeln('🔒 Fixing permissions for modular structure...');

        $this->run("chmod -R 755 {$releasePath}/app-modules || true");
        $this->run("find {$releasePath}/app-modules -type f -exec chmod 644 {} \\; || true");
        $this->run("find {$releasePath}/app-modules -type d -exec chmod 755 {} \\; || true");

        $this->deployer->writeln('✅ Module permissions fixed');
    }
}
