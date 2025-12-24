<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'laravel-deployer:install';

    protected $description = 'Install Laravel Deployer by generating deploy.json configuration file';

    public function handle(): int
    {
        $this->info('Installing Laravel Deployer...');

        $projectRoot = base_path();
        $deployDir = $projectRoot.'/.deploy';
        $deployJsonPath = $deployDir.'/deploy.json';
        $gitignorePath = $projectRoot.'/.gitignore';

        // Create .deploy directory first
        $this->createDeployDirectory($deployDir);

        // Generate deploy.json inside .deploy directory
        $this->generateDeployJson($deployJsonPath);

        // Update .gitignore (track deploy.json, ignore .env.*)
        $this->updateGitignore($gitignorePath);

        $this->newLine();
        $this->info('Laravel Deployer has been installed successfully!');
        $this->newLine();
        $this->info('Generated files:');
        $this->line('  .deploy/deploy.json - Main deployment configuration (tracked in git)');
        $this->line('  .deploy/.env.staging.example - Example secrets for staging');
        $this->line('  .deploy/.env.production.example - Example secrets for production');
        $this->line('  .deploy/.env.local.example - Example for local deployments');
        $this->newLine();
        $this->info('Next steps:');
        $this->line('1. Edit .deploy/deploy.json with your deployment settings');
        $this->line('2. Copy example files to actual environment files:');
        $this->line('   cp .deploy/.env.staging.example .deploy/.env.staging');
        $this->line('   cp .deploy/.env.production.example .deploy/.env.production');
        $this->line('3. Edit the .env files with your server credentials');
        $this->line('4. Run your first deployment:');
        $this->line('   php artisan deploy staging');
        $this->newLine();
        $this->info('Available commands:');
        $this->line('  php artisan deploy <env> - Deploy to specified environment');
        $this->line('  php artisan deploy:rollback <env> - Rollback to previous release');
        $this->line('  php artisan database:backup <env> - Backup database');
        $this->line('  php artisan database:download <env> - Download database backup');
        $this->line('  php artisan deployer:clear <env> - Clear caches on server');
        $this->newLine();

        return self::SUCCESS;
    }

    private function generateDeployJson(string $deployJsonPath): void
    {
        // Check if deploy.json already exists
        if (File::exists($deployJsonPath)) {
            $backupPath = $deployJsonPath.'.backup.'.date('Y-m-d_H-i-s');
            File::copy($deployJsonPath, $backupPath);
            $this->info('deploy.json already exists. Taking backup before creating new one.');
            $this->info('Backup saved to: '.basename($backupPath));
        }

        // Get the stub path
        $stubPath = __DIR__.'/../../stubs/deploy.json';

        if (! File::exists($stubPath)) {
            $this->error('deploy.json stub file not found at: '.$stubPath);

            return;
        }

        // Read the stub content
        $stubContent = File::get($stubPath);

        // Write the deploy.json file
        File::put($deployJsonPath, $stubContent);
        $this->info('.deploy/deploy.json generated');
    }

    private function createDeployDirectory(string $deployDir): void
    {
        // Create .deploy directory if it doesn't exist
        if (! File::exists($deployDir)) {
            File::makeDirectory($deployDir);
            $this->info('.deploy directory created');
        }

        // Generate .env.example files for environments
        $this->generateEnvExampleFile($deployDir.'/.env.staging.example', 'staging');
        $this->generateEnvExampleFile($deployDir.'/.env.production.example', 'production');
        $this->generateEnvExampleFile($deployDir.'/.env.local.example', 'local');
    }

    private function generateEnvExampleFile(string $envFilePath, string $environment): void
    {
        // Check if env file already exists
        if (File::exists($envFilePath)) {
            $this->warn(basename($envFilePath).' already exists. Skipping generation.');

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
        $this->info(basename($envFilePath).' generated');
    }

    private function updateGitignore(string $gitignorePath): void
    {
        // New pattern: ignore all .deploy/* except deploy.json
        $gitignoreEntries = <<<'GITIGNORE'
# Laravel Deployer - track config, ignore secrets
.deploy/*
!.deploy/deploy.json
GITIGNORE;

        if (! File::exists($gitignorePath)) {
            File::put($gitignorePath, $gitignoreEntries.PHP_EOL);
            $this->info('.gitignore created with .deploy/ pattern');

            return;
        }

        $gitignoreContent = File::get($gitignorePath);

        // Check if already configured
        if (str_contains($gitignoreContent, '!.deploy/deploy.json')) {
            $this->info('.deploy/ already properly configured in .gitignore');

            return;
        }

        // Remove old .deploy/ entry if exists
        $gitignoreContent = preg_replace('/^\.deploy\/?\s*$/m', '', $gitignoreContent);

        // Add new entries
        $gitignoreContent = rtrim($gitignoreContent).PHP_EOL.PHP_EOL.$gitignoreEntries.PHP_EOL;
        File::put($gitignorePath, $gitignoreContent);
        $this->info('.deploy/ pattern updated in .gitignore (tracking deploy.json only)');
    }
}
