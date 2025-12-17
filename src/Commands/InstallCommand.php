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
        $deployYamlPath = $projectRoot.'/.deploy/deploy.yaml';
        $deployDirPath = $projectRoot.'/.deploy';
        $gitignorePath = $projectRoot.'/.gitignore';

        // Create .deploy directory first
        $this->createDeployDirectory($deployDirPath);

        // Generate deploy.yaml inside .deploy directory
        $this->generateDeployYaml($deployYamlPath);

        // Update .gitignore
        $this->updateGitignore($gitignorePath);

        $this->newLine();
        $this->info('✅ Laravel Deployer has been installed successfully!');
        $this->newLine();
        $this->info('Generated files:');
        $this->line('• .deploy/deploy.yaml - Main deployment configuration');
        $this->line('• .deploy/.env.staging.example - Example environment variables for staging');
        $this->line('• .deploy/.env.production.example - Example environment variables for production');
        $this->line('• .deploy/.env.local.example - Example environment variables for local deployments');
        $this->newLine();
        $this->info('📌 Note: The .deploy/ directory is gitignored for security');
        $this->newLine();
        $this->info('Next steps:');
        $this->line('1. Copy example files to actual environment files:');
        $this->line('   cp .deploy/.env.staging.example .deploy/.env.staging');
        $this->line('   cp .deploy/.env.production.example .deploy/.env.production');
        $this->line('2. Edit .deploy/deploy.yaml with your server details');
        $this->line('3. Edit the .env files with your actual server credentials');
        $this->line('4. Run your first deployment:');
        $this->line('   php artisan deploy staging');
        $this->newLine();
        $this->info('Available commands:');
        $this->line('• php artisan deploy <env> - Deploy to specified environment');
        $this->line('• php artisan database:backup <env> - Backup database');
        $this->line('• php artisan database:download <env> - Download database backup');
        $this->line('• php artisan database:upload <backup> - Upload database backup');
        $this->line('• php artisan database:restore <backup> - Restore local database');
        $this->line('• php artisan deployer:clear <env> - Clear caches on server');
        $this->newLine();

        return self::SUCCESS;
    }

    private function generateDeployYaml(string $deployYamlPath): void
    {
        // Check if deploy.yaml already exists
        if (File::exists($deployYamlPath)) {
            $backupPath = $deployYamlPath.'.backup.'.date('Y-m-d_H-i-s');
            File::copy($deployYamlPath, $backupPath);
            $this->info('📁 deploy.yaml already exists. Taking backup before creating new one.');
            $this->info('💾 Backup saved to: '.basename($backupPath));
        }

        // Get the stub path
        $stubPath = __DIR__.'/../../stubs/deploy.yaml';

        if (! File::exists($stubPath)) {
            $this->error('deploy.yaml stub file not found at: '.$stubPath);

            return;
        }

        // Read the stub content
        $stubContent = File::get($stubPath);

        // Replace placeholders with project-specific values
        $appName = config('app.name', 'Laravel Application');
        $stubContent = str_replace('Your Application Name', $appName, $stubContent);

        // Write the deploy.yaml file
        File::put($deployYamlPath, $stubContent);
        $this->info('📝 .deploy/deploy.yaml generated');
    }

    private function createDeployDirectory(string $deployDirPath): void
    {
        // Create .deploy directory if it doesn't exist
        if (! File::exists($deployDirPath)) {
            File::makeDirectory($deployDirPath);
            $this->info('📁 .deploy directory created');
        }

        // Generate .env.example files for environments
        $this->generateEnvExampleFile($deployDirPath.'/.env.staging.example', 'staging');
        $this->generateEnvExampleFile($deployDirPath.'/.env.production.example', 'production');
        $this->generateEnvExampleFile($deployDirPath.'/.env.local.example', 'local');
    }

    private function generateEnvExampleFile(string $envFilePath, string $environment): void
    {
        // Check if env file already exists
        if (File::exists($envFilePath)) {
            $this->warn('📁 '.basename($envFilePath).' already exists. Skipping generation.');

            return;
        }

        // Get the stub path
        $stubPath = __DIR__."/../../stubs/.env.{$environment}.example";

        if (! File::exists($stubPath)) {
            $this->error(".env.{$environment}.example stub file not found at: {$stubPath}");

            return;
        }

        // Copy the stub content
        File::copy($stubPath, $envFilePath);
        $this->info('📝 '.basename($envFilePath).' generated');
    }

    private function updateGitignore(string $gitignorePath): void
    {
        $gitignoreEntry = '.deploy/';

        if (! File::exists($gitignorePath)) {
            File::put($gitignorePath, $gitignoreEntry.PHP_EOL);
            $this->info('📝 .gitignore created and .deploy/ added');

            return;
        }

        $gitignoreContent = File::get($gitignorePath);

        if (strpos($gitignoreContent, $gitignoreEntry) !== false) {
            $this->info('📝 .deploy/ already in .gitignore');

            return;
        }

        // Add .deploy/ to .gitignore
        $gitignoreContent .= PHP_EOL.$gitignoreEntry.PHP_EOL;
        File::put($gitignorePath, $gitignoreContent);
        $this->info('📝 .deploy/ added to .gitignore');
    }
}
