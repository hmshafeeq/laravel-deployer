<?php

namespace Shaf\LaravelDeployer\Actions\Deployment;

use Shaf\LaravelDeployer\Services\SharedResourceLinker;
use Shaf\LaravelDeployer\Services\PermissionManager;
use Shaf\LaravelDeployer\Services\SystemCommandDetector;
use Shaf\LaravelDeployer\Support\Abstract\DeploymentAction;
use Shaf\LaravelDeployer\Deployer;

class ConfigureReleaseAction extends DeploymentAction
{
    public function __construct(
        protected Deployer $deployer,
        protected ?SharedResourceLinker $resourceLinker = null,
        protected ?PermissionManager $permissionManager = null,
        protected ?SystemCommandDetector $systemDetector = null
    ) {
        parent::__construct($deployer);
        $this->systemDetector = $systemDetector ?? new SystemCommandDetector($deployer);
        $this->resourceLinker = $resourceLinker ?? new SharedResourceLinker($deployer);
        $this->permissionManager = $permissionManager ?? new PermissionManager($deployer, $this->systemDetector);
    }

    public function execute(): void
    {
        $releasePath = $this->getReleasePath();

        // Link shared resources (storage, .env)
        $this->resourceLinker->linkSharedResources();

        // Install vendor dependencies
        $this->installVendors($releasePath);

        // Setup writable directories and permissions
        $this->setupPermissions($releasePath);
    }

    /**
     * Install vendor dependencies using composer
     */
    protected function installVendors(string $releasePath): void
    {
        $composerOptions = config('laravel-deployer.composer.options');

        // Check if unzip is available
        $this->systemDetector->hasUnzip();

        // Check for composer.phar
        $this->writeln("run if [ -f {$this->getDeployPath()}/.dep/composer.phar ]; then echo +true; fi");
        $this->cmd("if [ -f {$this->getDeployPath()}/.dep/composer.phar ]; then echo +true; fi");

        // Check if composer command is available
        $this->systemDetector->hasComposer();

        // Get composer and PHP paths
        $composerPath = $this->systemDetector->getComposerPath();
        $phpPath = $this->systemDetector->getPhpPath();

        // Run composer install
        $composerCommand = "cd {$releasePath} && {$phpPath} {$composerPath} install {$composerOptions} 2>&1";
        $this->writeln("run {$composerCommand}");

        $result = $this->cmd($composerCommand);
        if (!empty($result)) {
            $lines = explode("\n", $result);
            foreach ($lines as $line) {
                $this->writeln($line);
            }
        }
    }

    /**
     * Setup writable directories and permissions
     */
    protected function setupPermissions(string $releasePath): void
    {
        // Check if release symlink exists
        $this->writeln("run if [ -h {$this->getDeployPath()}/release ]; then echo +correct; fi");
        $result = $this->cmd("if [ -h {$this->getDeployPath()}/release ]; then echo +correct; fi");
        if (!empty($result)) {
            $this->writeln($result);
        }

        // Create writable directories
        $this->permissionManager->createWritableDirectories($releasePath);

        // Set ACL permissions
        $this->permissionManager->setAclPermissions($releasePath);

        // Fix module permissions if app-modules directory exists
        $moduleExists = $this->deployer->test("[ -d {$releasePath}/app-modules ]");
        if ($moduleExists) {
            $this->permissionManager->fixModulePermissions($releasePath);
        }
    }
}
