# Install Command

Initialize Laravel Deployer in your project.

## Command

```bash
php artisan laravel-deployer:install
```

## Arguments

None

## Options

None

## Usage Examples

```bash
# Run installation
php artisan laravel-deployer:install
```

## What It Does

The install command sets up Laravel Deployer in your project:

1. **Creates Configuration Directory** - Creates `.deploy/` folder
2. **Publishes Configuration File** - Creates `deploy.json` template
3. **Creates Environment Templates** - Creates `.env.{environment}.example` files
4. **Updates .gitignore** - Adds deployment credentials to `.gitignore`
5. **Displays Success Message** - Shows next steps

## Files Created

```
your-laravel-project/
├── .deploy/
│   ├── deploy.json                  ← Main configuration
│   ├── .env.staging.example         ← Staging credentials template
│   └── .env.production.example      ← Production credentials template
└── .gitignore                       ← Updated with .deploy/*.env
```

## Configuration Template

The generated `deploy.json` contains:

```yaml
hosts:
  staging:
    hostname: staging.yourapp.com
    remote_user: deploy
    deploy_path: /var/www/staging
    branch: main
    health_check_endpoints:
      - url: https://staging.yourapp.com/health
        status: 200

  production:
    hostname: yourapp.com
    remote_user: deploy
    deploy_path: /var/www/production
    branch: production
    health_check_endpoints:
      - url: https://yourapp.com/health
        status: 200

config:
  repository: git@github.com:youruser/yourrepo.git
  keep_releases: 3
  composer_options: '--no-dev --optimize-autoloader'
  shared_dirs:
    - storage
  shared_files:
    - .env
  writable_dirs:
    - storage
    - storage/app
    - storage/framework
    - storage/logs
    - bootstrap/cache
  rsync_excludes:
    - .git/
    - node_modules/
    - .env
    - storage/
    - tests/
  rsync_includes:
    - app/
    - bootstrap/
    - config/
    - database/
    - public/
    - resources/
    - routes/
    - composer.json
    - composer.lock
    - artisan
```

## Environment Template

The generated `.env.{environment}.example` contains:

```env
# Server Connection
DEPLOY_HOST=yourapp.com
DEPLOY_USER=deploy
DEPLOY_PATH=/var/www/production
DEPLOY_BRANCH=main

# Optional: Notifications
DEPLOY_SLACK_WEBHOOK=
DEPLOY_DISCORD_WEBHOOK=

# Optional: Custom Configuration
DEPLOY_KEEP_RELEASES=3
```

## After Installation

### 1. Configure Deployment Settings

```bash
# Edit deploy.json
nano .deploy/deploy.json
```

Update:
- `hostname` - Your server domain/IP
- `remote_user` - SSH username
- `deploy_path` - Deployment directory on server
- `branch` - Git branch to deploy
- `repository` - Your Git repository URL

### 2. Create Environment Credentials

```bash
# Copy templates
cp .deploy/.env.staging.example .deploy/.env.staging
cp .deploy/.env.production.example .deploy/.env.production

# Edit with your credentials
nano .deploy/.env.staging
nano .deploy/.env.production
```

### 3. Setup SSH Keys

```bash
# Generate SSH key for deployment
php artisan deploy:key-generate deploy@yourapp.com

# Follow prompts to copy to server
```

### 4. Prepare Server

On your server, create deployment directory:

```bash
ssh deploy@yourserver.com
mkdir -p /var/www/production
exit
```

### 5. First Deployment

```bash
# Test with staging
php artisan deploy staging

# Deploy to production
php artisan deploy production
```

## Installation Scenarios

### New Project
```bash
# 1. Install Laravel Deployer
composer require shaf/laravel-deployer

# 2. Run installation
php artisan laravel-deployer:install

# 3. Configure as needed
# ... (steps above)
```

### Existing Project
```bash
# 1. Install package
composer require shaf/laravel-deployer

# 2. Run installation
php artisan laravel-deployer:install

# 3. Migrate from existing deployment
# ... customize deploy.json with your settings
```

### Team Project
```bash
# 1. Install package
composer require shaf/laravel-deployer

# 2. Run installation (only once per project)
php artisan laravel-deployer:install

# 3. Commit deploy.json
git add .deploy/deploy.json
git commit -m "Add deployment configuration"

# 4. Each team member creates own credentials
cp .deploy/.env.production.example .deploy/.env.production
# Edit with personal settings (never commit this!)
```

## What Gets Ignored

The install command adds to `.gitignore`:

```gitignore
# Laravel Deployer
.deploy/.env.*
!.deploy/.env.*.example
.deploy/downloads/
```

This ensures:
- ✅ Configuration (`deploy.json`) is committed
- ✅ Credential templates (`.env.*.example`) are committed
- ❌ Actual credentials (`.env.*`) are NOT committed
- ❌ Downloaded backups are NOT committed

## Re-running Installation

If you run the command again:
- Existing files are **not overwritten**
- Missing files are created
- You'll see warnings for existing files

To force reinstall:
```bash
# Backup your configuration
cp .deploy/deploy.json .deploy/deploy.json.backup

# Remove existing configuration
rm -rf .deploy/

# Run installation again
php artisan laravel-deployer:install

# Restore your configuration
mv .deploy/deploy.json.backup .deploy/deploy.json
```

## Customization

### Multiple Environments

Add more environments to `deploy.json`:

```yaml
hosts:
  staging:
    # ... staging config

  production:
    # ... production config

  demo:
    hostname: demo.yourapp.com
    remote_user: deploy
    deploy_path: /var/www/demo
    branch: demo

  qa:
    hostname: qa.yourapp.com
    remote_user: deploy
    deploy_path: /var/www/qa
    branch: develop
```

Then create corresponding `.env` files:
```bash
cp .deploy/.env.staging.example .deploy/.env.demo
cp .deploy/.env.staging.example .deploy/.env.qa
```

### Custom Shared Directories

Edit `deploy.json`:

```yaml
config:
  shared_dirs:
    - storage
    - public/uploads      # ← Add custom directory
    - public/media        # ← Add custom directory
```

### Custom Rsync Excludes

Edit `deploy.json`:

```yaml
config:
  rsync_excludes:
    - .git/
    - node_modules/
    - .env
    - storage/
    - tests/
    - .idea/              # ← Add IDE files
    - .vscode/            # ← Add IDE files
    - *.log               # ← Add log files
```

## Troubleshooting

### Permission Denied Creating .deploy/
```
Permission denied: .deploy
```
**Fix**: Ensure you have write permissions in project root

### deploy.json Already Exists
```
Configuration file already exists
```
**Fix**: This is normal. Command won't overwrite existing config

### .gitignore Not Updated
```
Could not update .gitignore
```
**Fix**: Manually add:
```gitignore
.deploy/.env.*
!.deploy/.env.*.example
```

## Related Commands

- [`deploy:key-generate`](ssh-key-generate.md) - Generate SSH keys
- [`deploy`](deploy.md) - Deploy application
- [`database:backup`](database-backup.md) - Backup database

## Tips

- **Run once per project** during initial setup
- **Commit deploy.json** to share configuration with team
- **Never commit credentials** (`.env.*` files)
- **Document custom settings** in project README
- **Review templates** before copying to actual env files

## Architecture

This command:
- Publishes configuration stubs from package
- Creates directory structure
- Updates `.gitignore` automatically

See: `src/Commands/InstallCommand.php`
