<?php

namespace Shaf\LaravelDeployer\Services;

use Shaf\LaravelDeployer\Constants\Commands;
use Shaf\LaravelDeployer\Contracts\CommandExecutor;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\ServerConnection;
use Shaf\LaravelDeployer\Deployer\DeploymentTasks;
use Shaf\LaravelDeployer\Deployer\HealthCheckTasks;
use Shaf\LaravelDeployer\Deployer\NotificationTasks;
use Shaf\LaravelDeployer\Deployer\ServiceTasks;
use Symfony\Component\Console\Output\OutputInterface;

class DeploymentServiceFactory
{
    private ConfigurationService $configService;
    private OutputService $output;
    private DeploymentConfig $config;
    private CommandExecutor $executor;
    private string $releaseName = '';

    public function __construct(
        private string $basePath,
        private OutputInterface $consoleOutput,
    ) {
        $this->configService = new ConfigurationService($basePath);
    }

    public function createForEnvironment(string $environment): self
    {
        // Load configuration
        $this->config = $this->configService->load($environment);

        // Create output service
        $this->output = new OutputService(
            $this->consoleOutput,
            "[{$environment}]"
        );

        // Create command executor
        $this->executor = $this->createCommandExecutorInternal();

        return $this;
    }

    public function generateReleaseName(): string
    {
        $releaseManager = $this->createReleaseManager();
        $this->releaseName = $releaseManager->generateReleaseName();

        return $this->releaseName;
    }

    public function getReleaseName(): string
    {
        return $this->releaseName;
    }

    public function setReleaseName(string $releaseName): void
    {
        $this->releaseName = $releaseName;
    }

    public function createDeploymentTasks(): DeploymentTasks
    {
        $tasks = new DeploymentTasks(
            $this->executor,
            $this->output,
            $this->config,
            $this->releaseName
        );

        // Inject dependencies
        $tasks->setArtisanRunner($this->createArtisanRunner());
        $tasks->setLockManager($this->createLockManager());
        $tasks->setReleaseManager($this->createReleaseManager());
        $tasks->setRsyncService($this->createRsyncService());

        return $tasks;
    }

    public function createHealthCheckTasks(): HealthCheckTasks
    {
        return new HealthCheckTasks(
            $this->executor,
            $this->output,
            $this->config,
            $this->releaseName
        );
    }

    public function createServiceTasks(): ServiceTasks
    {
        return new ServiceTasks(
            $this->executor,
            $this->output,
            $this->config,
            $this->releaseName
        );
    }

    public function createNotificationTasks(): NotificationTasks
    {
        return new NotificationTasks(
            $this->executor,
            $this->output,
            $this->config,
            $this->releaseName
        );
    }

    public function getConfig(): DeploymentConfig
    {
        return $this->config;
    }

    public function getOutput(): OutputService
    {
        return $this->output;
    }

    public function createLockManager(): LockManager
    {
        $releaseManager = $this->createReleaseManager();

        return new LockManager(
            $this->executor,
            $this->output,
            $this->config->deployPath,
            $releaseManager->getUser()
        );
    }

    public function createReleaseManager(): ReleaseManager
    {
        return new ReleaseManager(
            $this->executor,
            $this->output,
            $this->config->deployPath
        );
    }

    public function createCommandExecutor(): CommandExecutor
    {
        if ($this->executor) {
            return $this->executor;
        }

        return $this->createCommandExecutorInternal();
    }

    private function createCommandExecutorInternal(): CommandExecutor
    {
        if ($this->config->isLocal) {
            return new LocalCommandExecutor(
                $this->output,
                $this->basePath
            );
        }

        $connection = ServerConnection::fromConfig($this->config);

        return new RemoteCommandExecutor(
            $connection,
            $this->output,
            $this->config->deployPath
        );
    }

    private function createArtisanRunner(): ArtisanTaskRunner
    {
        return new ArtisanTaskRunner(
            $this->executor,
            $this->output,
            $this->config->deployPath . '/releases/' . $this->releaseName,
            Commands::PHP_BINARY
        );
    }

    private function createRsyncService(): RsyncService
    {
        return new RsyncService(
            $this->output,
            $this->config,
            $this->basePath
        );
    }

    public function confirmDeployment(bool $skipConfirm): bool
    {
        if ($skipConfirm) {
            $this->output->warning("Skipping deployment confirmation (--no-confirm flag used)");
            $this->output->newLine();
            return true;
        }

        $this->consoleOutput->writeln('');
        $this->consoleOutput->writeln("\033[33m═══════════════════════════════════════════════════════════\033[0m");
        $this->consoleOutput->writeln("\033[33m                 DEPLOYMENT CONFIRMATION\033[0m");
        $this->consoleOutput->writeln("\033[33m═══════════════════════════════════════════════════════════\033[0m");
        $this->consoleOutput->writeln('');
        $this->consoleOutput->writeln("  \033[32mEnvironment:\033[0m  \033[36m{$this->config->environment->value}\033[0m");
        $this->consoleOutput->writeln("  \033[32mServer:\033[0m       \033[36m{$this->config->hostname}\033[0m");
        $this->consoleOutput->writeln("  \033[32mUser:\033[0m         \033[36m{$this->config->remoteUser}\033[0m");
        $this->consoleOutput->writeln("  \033[32mDeploy Path:\033[0m  \033[36m{$this->config->deployPath}\033[0m");
        $this->consoleOutput->writeln('');

        if ($this->config->environment->isProduction()) {
            $this->consoleOutput->writeln("\033[31m⚠️  WARNING: You are deploying to PRODUCTION!\033[0m");
            $this->consoleOutput->writeln('');
        }

        $this->consoleOutput->writeln("\033[33m═══════════════════════════════════════════════════════════\033[0m");
        $this->consoleOutput->writeln('');

        $this->consoleOutput->write("  Do you want to continue with this deployment? [Y/n] ");
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        $confirmed = trim(strtolower($line)) !== 'n';
        fclose($handle);

        if (!$confirmed) {
            $this->consoleOutput->writeln('');
            $this->consoleOutput->writeln("\033[33m🛑 Deployment cancelled by user\033[0m");
            $this->consoleOutput->writeln('');
            return false;
        }

        $this->consoleOutput->writeln('');
        $this->consoleOutput->writeln("\033[32m✓ Deployment confirmed, proceeding...\033[0m");
        $this->consoleOutput->writeln('');

        return true;
    }
}
