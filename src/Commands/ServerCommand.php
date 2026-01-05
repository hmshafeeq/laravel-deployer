<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\ConfigService;
use Shaf\LaravelDeployer\Services\DeploymentService;

class ServerCommand extends Command
{
    protected $signature = 'deployer:server
                            {action : Action to perform (clear, provision)}
                            {environment? : The deployment environment (required for clear)}
                            {--no-confirm : Skip confirmation prompt}
                            {--host= : Server hostname or IP address (for provision)}
                            {--port=22 : SSH port}
                            {--user=ubuntu : SSH user (default: ubuntu)}
                            {--password= : SSH password (if not using key)}
                            {--key= : Path to SSH private key}
                            {--create-user : Create a new deployment user}
                            {--deploy-user=deployer : Name of the deployment user to create}
                            {--php-version=8.3 : PHP version to install}
                            {--nodejs-version=20 : Node.js version to install}
                            {--with-mysql : Install MySQL}
                            {--with-postgresql : Install PostgreSQL}
                            {--with-redis : Install Redis}
                            {--non-interactive : Run in non-interactive mode}';

    protected $description = 'Server management: clear caches, provision new servers';

    protected array $config = [];

    protected string $scriptsDir;

    protected string $remoteScriptsDir = '/tmp/laravel-deployer-provision';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'clear' => $this->handleClear(),
            'provision' => $this->handleProvision(),
            default => $this->showUsage(),
        };
    }

    // =========================================================================
    // CLEAR - Clear caches and restart services
    // =========================================================================

    protected function handleClear(): int
    {
        $environment = $this->argument('environment');

        if (! $environment) {
            $this->error('Environment is required for clear action.');
            $this->line('');
            $this->line('Usage: php artisan deployer:server clear {environment}');

            return self::FAILURE;
        }

        $noConfirm = $this->option('no-confirm');

        try {
            // Load configuration
            $config = ConfigService::load($environment, base_path(), $this->output);

            // Initialize services
            $cmd = new CommandService($config, $this->output);
            $deployment = new DeploymentService($config, $cmd, base_path());

            // Show confirmation for non-local environments
            if (! $config->isLocal && ! $noConfirm) {
                $this->warn("⚠️  You are about to clear caches and restart services on {$environment}");

                if (! $this->confirm('Do you want to continue?', false)) {
                    $this->info('Operation cancelled.');

                    return self::SUCCESS;
                }
            }

            $this->info("Clearing caches and restarting services on {$environment}...");
            $this->newLine();

            // Get current release path
            $currentPath = $deployment->getCurrentPath();

            // Clear Laravel caches
            $this->info('🗑️  Clearing Laravel caches...');

            $results = [
                'config' => $this->runArtisanCommand($cmd, $currentPath, 'config:clear'),
                'view' => $this->runArtisanCommand($cmd, $currentPath, 'view:clear'),
                'route' => $this->runArtisanCommand($cmd, $currentPath, 'route:clear'),
                'queue' => $this->runArtisanCommand($cmd, $currentPath, 'queue:restart'),
            ];

            // Display results
            $this->info($results['config'] ? '  ✓ Config cache cleared' : '  ⚠ Config cache operation failed');
            $this->info($results['view'] ? '  ✓ View cache cleared' : '  ⚠ View cache operation failed');
            $this->info($results['route'] ? '  ✓ Route cache cleared' : '  ⚠ Route cache operation failed');

            // Restart queue workers
            $this->newLine();
            $this->info('🔄 Restarting queue workers...');
            $this->info($results['queue'] ? '  ✓ Queue workers restarted' : '  ⚠ Queue restart failed');

            // Restart PHP-FPM (if not local)
            if (! $config->isLocal) {
                $this->newLine();
                $this->info('🔄 Restarting PHP-FPM...');

                try {
                    $cmd->restartPhpFpm();
                } catch (\Exception $e) {
                    $this->warn('  ⚠ PHP-FPM restart failed: '.$e->getMessage());
                }
            }

            $this->newLine();
            $this->info('✅ System clear completed successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ System clear failed!');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Run an artisan command on the remote server
     */
    private function runArtisanCommand(CommandService $cmd, string $path, string $command): bool
    {
        try {
            $cmd->artisan($command, $path);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // PROVISION - Provision a fresh Ubuntu server
    // =========================================================================

    protected function handleProvision(): int
    {
        $this->scriptsDir = dirname(__DIR__, 2).'/scripts';

        $this->info('🚀 Laravel Deployer - Server Provisioning Tool');
        $this->newLine();

        // Collect configuration
        if (! $this->option('non-interactive')) {
            $this->collectConfiguration();
        } else {
            $this->loadConfigurationFromOptions();
        }

        // Validate configuration
        if (! $this->validateConfiguration()) {
            return self::FAILURE;
        }

        // Display configuration summary
        $this->displayConfigurationSummary();

        if (! $this->option('non-interactive')) {
            if (! $this->confirm('Do you want to proceed with the provisioning?', true)) {
                $this->warn('Provisioning cancelled.');

                return self::SUCCESS;
            }
        }

        // Test SSH connection
        $this->info('🔌 Testing SSH connection...');
        if (! $this->testConnection()) {
            $this->error('Failed to connect to the server. Please check your credentials.');

            return self::FAILURE;
        }
        $this->info('✅ Connection successful!');
        $this->newLine();

        // Upload provision scripts
        $this->info('📤 Uploading provision scripts...');
        if (! $this->uploadScripts()) {
            $this->error('Failed to upload provision scripts.');

            return self::FAILURE;
        }
        $this->info('✅ Scripts uploaded successfully!');
        $this->newLine();

        // Execute provisioning
        $this->info('⚙️  Starting server provisioning...');
        $this->newLine();

        if (! $this->executeProvisioning()) {
            $this->error('Provisioning failed. Please check the error messages above.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('🎉 Server provisioned successfully!');
        $this->displayPostProvisionInfo();

        return self::SUCCESS;
    }

    protected function collectConfiguration(): void
    {
        $this->info('📋 Server Configuration');
        $this->newLine();

        // Server details
        $this->config['host'] = $this->option('host') ?: $this->ask('Server hostname or IP address');
        $this->config['port'] = $this->option('port') ?: $this->ask('SSH port', '22');
        $this->config['user'] = $this->option('user') ?: $this->ask('SSH user', 'ubuntu');

        // Authentication
        $authMethod = $this->choice(
            'Authentication method',
            ['SSH Key', 'Password'],
            0
        );

        if ($authMethod === 'SSH Key') {
            $defaultKeyPath = $_SERVER['HOME'].'/.ssh/id_rsa';
            $this->config['key'] = $this->option('key') ?: $this->ask(
                'Path to SSH private key',
                file_exists($defaultKeyPath) ? $defaultKeyPath : null
            );
            $this->config['password'] = null;
        } else {
            $this->config['password'] = $this->secret('SSH password');
            $this->config['key'] = null;
        }

        $this->newLine();

        // User creation
        $this->config['create_user'] = $this->option('create-user') ?: $this->confirm(
            'Do you want to create a new deployment user?',
            true
        );

        if ($this->config['create_user']) {
            $this->config['deploy_user'] = $this->option('deploy-user') ?: $this->ask(
                'Deployment user name',
                'deployer'
            );
            $this->config['deploy_password'] = $this->secret('Password for deployment user (leave empty for SSH key only)');
        }

        $this->newLine();

        // Software versions
        $this->config['php_version'] = $this->option('php-version') ?: $this->choice(
            'PHP version',
            ['8.3', '8.2', '8.1'],
            0
        );

        $this->config['nodejs_version'] = $this->option('nodejs-version') ?: $this->choice(
            'Node.js version',
            ['20', '18', '16'],
            0
        );

        $this->newLine();

        // Database selection
        $this->info('📦 Database Installation (select multiple by comma-separating numbers)');
        $databases = $this->choice(
            'Which databases would you like to install?',
            ['MySQL', 'PostgreSQL', 'Redis', 'None'],
            2,
            null,
            true
        );

        $this->config['install_mysql'] = in_array('MySQL', $databases);
        $this->config['install_postgresql'] = in_array('PostgreSQL', $databases);
        $this->config['install_redis'] = in_array('Redis', $databases);

        if ($this->config['install_mysql']) {
            $this->config['mysql_root_password'] = $this->secret('MySQL root password');
        }

        if ($this->config['install_postgresql']) {
            $this->config['postgres_password'] = $this->secret('PostgreSQL password');
        }

        $this->newLine();

        // Additional features
        $this->config['install_supervisor'] = $this->confirm('Install Supervisor (for queue workers)?', true);
        $this->config['setup_firewall'] = $this->confirm('Setup UFW firewall?', true);
        $this->config['setup_swap'] = $this->confirm('Setup swap space (recommended for servers with <2GB RAM)?', true);

        if ($this->config['setup_swap']) {
            $this->config['swap_size'] = $this->choice(
                'Swap size',
                ['1G', '2G', '4G'],
                1
            );
        }
    }

    protected function loadConfigurationFromOptions(): void
    {
        $this->config = [
            'host' => $this->option('host'),
            'port' => $this->option('port') ?: '22',
            'user' => $this->option('user') ?: 'ubuntu',
            'password' => $this->option('password'),
            'key' => $this->option('key'),
            'create_user' => $this->option('create-user'),
            'deploy_user' => $this->option('deploy-user') ?: 'deployer',
            'deploy_password' => null,
            'php_version' => $this->option('php-version') ?: '8.3',
            'nodejs_version' => $this->option('nodejs-version') ?: '20',
            'install_mysql' => $this->option('with-mysql'),
            'install_postgresql' => $this->option('with-postgresql'),
            'install_redis' => $this->option('with-redis'),
            'mysql_root_password' => null,
            'postgres_password' => null,
            'install_supervisor' => true,
            'setup_firewall' => true,
            'setup_swap' => true,
            'swap_size' => '2G',
        ];
    }

    protected function validateConfiguration(): bool
    {
        if (empty($this->config['host'])) {
            $this->error('Server hostname is required.');

            return false;
        }

        if (empty($this->config['key']) && empty($this->config['password'])) {
            $this->error('Either SSH key or password is required.');

            return false;
        }

        if (! empty($this->config['key']) && ! file_exists($this->config['key'])) {
            $this->error("SSH key file not found: {$this->config['key']}");

            return false;
        }

        return true;
    }

    protected function displayConfigurationSummary(): void
    {
        $this->newLine();
        $this->info('📝 Configuration Summary');
        $this->newLine();
        $this->table(
            ['Setting', 'Value'],
            [
                ['Server', $this->config['host'].':'.$this->config['port']],
                ['SSH User', $this->config['user']],
                ['Auth Method', $this->config['key'] ? 'SSH Key' : 'Password'],
                ['Create Deploy User', $this->config['create_user'] ? 'Yes ('.$this->config['deploy_user'].')' : 'No'],
                ['PHP Version', $this->config['php_version']],
                ['Node.js Version', $this->config['nodejs_version']],
                ['MySQL', $this->config['install_mysql'] ? 'Yes' : 'No'],
                ['PostgreSQL', $this->config['install_postgresql'] ? 'Yes' : 'No'],
                ['Redis', $this->config['install_redis'] ? 'Yes' : 'No'],
                ['Supervisor', $this->config['install_supervisor'] ? 'Yes' : 'No'],
                ['Firewall', $this->config['setup_firewall'] ? 'Yes' : 'No'],
                ['Swap', $this->config['setup_swap'] ? 'Yes ('.$this->config['swap_size'].')' : 'No'],
            ]
        );
        $this->newLine();
    }

    protected function testConnection(): bool
    {
        $sshCommand = $this->buildSSHCommand('echo "Connection test"');

        exec($sshCommand.' 2>&1', $output, $returnCode);

        return $returnCode === 0;
    }

    protected function uploadScripts(): bool
    {
        // Create remote directory
        $this->executeRemoteCommand("mkdir -p {$this->remoteScriptsDir}");

        // Upload all scripts
        $scriptsToUpload = [
            'provision.sh',
            'components/common.sh',
            'components/user.sh',
            'components/packages.sh',
            'components/php.sh',
            'components/nginx.sh',
            'components/database.sh',
            'components/nodejs.sh',
            'components/composer.sh',
            'components/supervisor.sh',
            'components/security.sh',
            'components/swap.sh',
        ];

        foreach ($scriptsToUpload as $script) {
            $localPath = $this->scriptsDir.'/'.$script;
            $remotePath = $this->remoteScriptsDir.'/'.$script;

            if (! file_exists($localPath)) {
                $this->warn("Script not found: {$script}");

                continue;
            }

            // Create remote directory if needed
            $remoteDir = dirname($remotePath);
            $this->executeRemoteCommand("mkdir -p {$remoteDir}");

            // Upload file
            $scpCommand = $this->buildSCPCommand($localPath, $remotePath);
            exec($scpCommand.' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                $this->error("Failed to upload {$script}");

                return false;
            }

            // Make executable
            $this->executeRemoteCommand("chmod +x {$remotePath}");
        }

        return true;
    }

    protected function executeProvisioning(): bool
    {
        // Generate config file content
        $configContent = $this->generateConfigFile();

        // Upload config
        $configPath = '/tmp/provision-config.sh';
        $tmpConfigFile = tempnam(sys_get_temp_dir(), 'provision-config');
        file_put_contents($tmpConfigFile, $configContent);

        $scpCommand = $this->buildSCPCommand($tmpConfigFile, $configPath);
        exec($scpCommand.' 2>&1', $output, $returnCode);
        unlink($tmpConfigFile);

        if ($returnCode !== 0) {
            $this->error('Failed to upload configuration file.');

            return false;
        }

        // Execute main provision script
        $provisionCommand = "sudo bash {$this->remoteScriptsDir}/provision.sh {$configPath}";

        $result = $this->executeRemoteCommandWithOutput($provisionCommand);

        // Cleanup
        $this->executeRemoteCommand("rm -rf {$this->remoteScriptsDir} {$configPath}");

        return $result;
    }

    protected function generateConfigFile(): string
    {
        $lines = [
            '#!/bin/bash',
            '',
            '# Provision Configuration',
            '# Generated by Laravel Deployer',
            '',
            '# User Configuration',
            'CREATE_USER='.(int) $this->config['create_user'],
            'DEPLOY_USER="'.$this->config['deploy_user'].'"',
            'DEPLOY_PASSWORD="'.($this->config['deploy_password'] ?? '').'"',
            '',
            '# Software Versions',
            'PHP_VERSION="'.$this->config['php_version'].'"',
            'NODEJS_VERSION="'.$this->config['nodejs_version'].'"',
            '',
            '# Database Configuration',
            'INSTALL_MYSQL='.(int) $this->config['install_mysql'],
            'INSTALL_POSTGRESQL='.(int) $this->config['install_postgresql'],
            'INSTALL_REDIS='.(int) $this->config['install_redis'],
            'MYSQL_ROOT_PASSWORD="'.($this->config['mysql_root_password'] ?? '').'"',
            'POSTGRES_PASSWORD="'.($this->config['postgres_password'] ?? '').'"',
            '',
            '# Additional Features',
            'INSTALL_SUPERVISOR='.(int) $this->config['install_supervisor'],
            'SETUP_FIREWALL='.(int) $this->config['setup_firewall'],
            'SETUP_SWAP='.(int) $this->config['setup_swap'],
            'SWAP_SIZE="'.($this->config['swap_size'] ?? '2G').'"',
        ];

        return implode("\n", $lines);
    }

    protected function buildSSHCommand(string $command): string
    {
        $sshCmd = 'ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR';
        $sshCmd .= ' -p '.$this->config['port'];

        if ($this->config['key']) {
            $sshCmd .= ' -i '.$this->config['key'];
        }

        $sshCmd .= ' '.$this->config['user'].'@'.$this->config['host'];
        $sshCmd .= ' "'.$command.'"';

        return $sshCmd;
    }

    protected function buildSCPCommand(string $localPath, string $remotePath): string
    {
        $scpCmd = 'scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR';
        $scpCmd .= ' -P '.$this->config['port'];

        if ($this->config['key']) {
            $scpCmd .= ' -i '.$this->config['key'];
        }

        $scpCmd .= ' '.$localPath;
        $scpCmd .= ' '.$this->config['user'].'@'.$this->config['host'].':'.$remotePath;

        return $scpCmd;
    }

    protected function executeRemoteCommand(string $command): bool
    {
        $sshCommand = $this->buildSSHCommand($command);
        exec($sshCommand.' 2>&1', $output, $returnCode);

        return $returnCode === 0;
    }

    protected function executeRemoteCommandWithOutput(string $command): bool
    {
        $sshCommand = $this->buildSSHCommand($command);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($sshCommand, $descriptorSpec, $pipes);

        if (! is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);

        // Read output in real-time
        while (! feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line !== false) {
                $this->line(rtrim($line));
            }
        }

        // Read errors
        $errors = stream_get_contents($pipes[2]);
        if (! empty($errors)) {
            $this->error($errors);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        return $returnCode === 0;
    }

    protected function displayPostProvisionInfo(): void
    {
        $this->newLine();
        $this->info('📋 Post-Provision Information');
        $this->newLine();

        $user = $this->config['create_user'] ? $this->config['deploy_user'] : $this->config['user'];

        $this->line('Server: '.$this->config['host']);
        $this->line('SSH User: '.$user);

        if ($this->config['create_user']) {
            $this->line('SSH Key: /home/'.$this->config['deploy_user'].'/.ssh/id_rsa');
            $this->newLine();
            $this->info('To download the SSH key for the deployment user:');
            $this->line('scp '.$this->config['user'].'@'.$this->config['host'].':/home/'.$this->config['deploy_user'].'/.ssh/id_rsa ./deploy_key');
        }

        $this->newLine();
        $this->info('Installed Software:');
        $this->line('• Nginx');
        $this->line('• PHP '.$this->config['php_version']);
        $this->line('• Node.js '.$this->config['nodejs_version']);
        $this->line('• Composer');

        if ($this->config['install_mysql']) {
            $this->line('• MySQL');
        }
        if ($this->config['install_postgresql']) {
            $this->line('• PostgreSQL');
        }
        if ($this->config['install_redis']) {
            $this->line('• Redis');
        }
        if ($this->config['install_supervisor']) {
            $this->line('• Supervisor');
        }

        $this->newLine();
        $this->info('Next Steps:');
        $this->line('1. Update your deploy.json with the server details');
        $this->line('2. Configure your .deploy/.env files');
        $this->line('3. Run: php artisan deployer staging');
    }

    protected function showUsage(): int
    {
        $this->error('Invalid action. Available actions:');
        $this->line('');
        $this->line('  php artisan deployer:server clear {env}       Clear caches and restart services');
        $this->line('  php artisan deployer:server provision         Provision a fresh Ubuntu server');
        $this->line('');
        $this->line('Examples:');
        $this->line('  php artisan deployer:server clear staging');
        $this->line('  php artisan deployer:server clear production --no-confirm');
        $this->line('  php artisan deployer:server provision --host=1.2.3.4 --user=ubuntu');

        return self::FAILURE;
    }
}
