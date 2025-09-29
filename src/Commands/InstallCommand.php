<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'laravel-deployer:install';

    protected $description = 'Install Laravel Deployer by generating deploy.yaml configuration file';

    public function handle(): int
    {
        $this->info('Installing Laravel Deployer...');

        $projectRoot = base_path();
        $deployYamlPath = $projectRoot . '/deploy.yaml';
        $deployDirPath = $projectRoot . '/.deploy';
        $gitignorePath = $projectRoot . '/.gitignore';

        // Generate deploy.yaml
        $this->generateDeployYaml($deployYamlPath);
        
        // Create .deploy directory structure
        $this->createDeployDirectory($deployDirPath);
        
        // Update .gitignore
        $this->updateGitignore($gitignorePath);

        $this->info('✅ Laravel Deployer has been installed successfully!');
        $this->line('');
        $this->info('Generated files:');
        $this->line('• deploy.yaml - Main deployment configuration');
        $this->line('• .deploy/ - Directory containing deployment configuration (added to .gitignore)');
        $this->line('  ├── hosts.json - Server configuration for each environment');
        $this->line('  ├── .env.staging - Environment variables for staging');
        $this->line('  └── .env.production - Environment variables for production');
        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Edit .deploy/hosts.json to configure your server details');
        $this->line('2. Edit .deploy/.env.staging and .deploy/.env.production with your environment variables');
        $this->line('3. Run your first deployment: dep deploy staging');
        $this->line('');
        $this->info('For more information, check the Laravel Deployer documentation.');

        return self::SUCCESS;
    }

    private function generateDeployYaml(string $deployYamlPath): void
    {
        // Check if deploy.yaml already exists
        if (File::exists($deployYamlPath)) {
            $backupPath = $deployYamlPath . '.backup.' . date('Y-m-d_H-i-s');
            File::copy($deployYamlPath, $backupPath);
            $this->info("📁 deploy.yaml already exists. Taking backup before creating new one.");
            $this->info("💾 Backup saved to: " . basename($backupPath));
        }

        // Get the stub path
        $stubPath = __DIR__ . '/../../stubs/deploy.yaml';

        if (! File::exists($stubPath)) {
            $this->error('deploy.yaml stub file not found at: ' . $stubPath);
            return;
        }

        // Read the stub content
        $stubContent = File::get($stubPath);

        // Replace placeholders with project-specific values
        $appName = config('app.name', 'Laravel Application');
        $stubContent = str_replace('Your Application Name', $appName, $stubContent);

        // Write the deploy.yaml file
        File::put($deployYamlPath, $stubContent);
        $this->info('📝 deploy.yaml generated');
    }

    private function createDeployDirectory(string $deployDirPath): void
    {
        // Create .deploy directory if it doesn't exist
        if (!File::exists($deployDirPath)) {
            File::makeDirectory($deployDirPath);
            $this->info('📁 .deploy directory created');
        }

        // Generate hosts.json
        $this->generateHostsJson($deployDirPath . '/hosts.json');
        
        // Generate .env files for environments
        $this->generateEnvFile($deployDirPath . '/.env.staging');
        $this->generateEnvFile($deployDirPath . '/.env.production');
    }

    private function generateHostsJson(string $hostsJsonPath): void
    {
        // Check if hosts.json already exists
        if (File::exists($hostsJsonPath)) {
            $this->warn('📁 hosts.json already exists. Skipping generation.');
            return;
        }

        // Get the stub path
        $stubPath = __DIR__ . '/../../stubs/hosts.json';

        if (! File::exists($stubPath)) {
            $this->error('hosts.json stub file not found at: ' . $stubPath);
            return;
        }

        // Copy the stub content
        File::copy($stubPath, $hostsJsonPath);
        $this->info('📝 hosts.json generated');
    }

    private function generateEnvFile(string $envFilePath): void
    {
        // Check if env file already exists
        if (File::exists($envFilePath)) {
            $this->warn('📁 ' . basename($envFilePath) . ' already exists. Skipping generation.');
            return;
        }

        // Get the stub path
        $stubPath = __DIR__ . '/../../stubs/.env';

        if (! File::exists($stubPath)) {
            $this->error('.env stub file not found at: ' . $stubPath);
            return;
        }

        // Copy the stub content
        File::copy($stubPath, $envFilePath);
        $this->info('📝 ' . basename($envFilePath) . ' generated');
    }

    private function updateGitignore(string $gitignorePath): void
    {
        $gitignoreEntry = '.deploy/';
        
        if (! File::exists($gitignorePath)) {
            File::put($gitignorePath, $gitignoreEntry . PHP_EOL);
            $this->info('📝 .gitignore created and .deploy/ added');
            return;
        }

        $gitignoreContent = File::get($gitignorePath);
        
        if (strpos($gitignoreContent, $gitignoreEntry) !== false) {
            $this->info('📝 .deploy/ already in .gitignore');
            return;
        }

        // Add .deploy/ to .gitignore
        $gitignoreContent .= PHP_EOL . $gitignoreEntry . PHP_EOL;
        File::put($gitignorePath, $gitignoreContent);
        $this->info('📝 .deploy/ added to .gitignore');
    }
}