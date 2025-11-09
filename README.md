# Laravel Deployer

A lightweight, zero-downtime deployment package for Laravel applications.

> **Simple, powerful deployment for Laravel apps.** Deploy your application with a single command using atomic symlink swapping for zero downtime.

## ✨ Features

- 🚀 **Zero-Downtime Deployment** - Atomic symlink swapping for seamless releases
- ⏪ **Instant Rollback** - One-command rollback to previous releases
- 📦 **Release Management** - Automatic versioning and cleanup
- 🔒 **Deployment Locking** - Prevents concurrent deployments
- 💾 **Database Operations** - Backup, download, upload, and restore
- 🔄 **Service Management** - Auto-restart PHP-FPM, Nginx, Supervisor
- ❤️ **Health Checks** - Pre-deployment resource and endpoint verification
- 🎨 **Beautiful Output** - Clear, colored progress indicators
- 📢 **Notifications** - Slack and Discord integration

## 📋 Requirements

- PHP 8.2+
- Laravel 11.x or 12.x
- SSH access to your server
- `rsync` installed locally and on server

## 🚀 Installation

### 1. Install via Composer

```bash
composer require shaf/laravel-deployer
```

### 2. Run Installation Command

```bash
php artisan laravel-deployer:install
```

This creates:
- `.deploy/deploy.yaml` - Deployment configuration
- `.deploy/.env.{environment}.example` - Environment templates
- `.gitignore` entries for credentials

## ⚙️ Configuration

### Basic Setup

**1. Edit `.deploy/deploy.yaml`:**

```yaml
hosts:
  staging:
    hostname: staging.yourapp.com
    remote_user: deploy
    deploy_path: /var/www/staging
    branch: main

  production:
    hostname: yourapp.com
    remote_user: deploy
    deploy_path: /var/www/production
    branch: production

config:
  keep_releases: 3
  composer_options: '--no-dev --optimize-autoloader'
```

**2. Create environment credentials:**

```bash
# Copy example files
cp .deploy/.env.staging.example .deploy/.env.staging
cp .deploy/.env.production.example .deploy/.env.production

# Edit with your credentials
nano .deploy/.env.staging
```

**Example `.env.staging`:**

```env
DEPLOY_HOST=staging.yourapp.com
DEPLOY_USER=deploy
DEPLOY_PATH=/var/www/staging
DEPLOY_BRANCH=main
```

**3. Setup SSH key authentication:**

```bash
# Generate SSH key if needed
ssh-keygen -t ed25519 -C "deploy@yourapp.com"

# Copy to server
ssh-copy-id deploy@staging.yourapp.com

# Test connection
ssh deploy@staging.yourapp.com
```

### Server Preparation

On your server, ensure the deployment path exists:

```bash
# On your server
sudo mkdir -p /var/www/staging
sudo chown deploy:deploy /var/www/staging
```

## 🎯 Usage

### Deploy Your Application

```bash
# Deploy to staging
php artisan deploy staging

# Deploy to production (with confirmation)
php artisan deploy production

# Skip confirmation
php artisan deploy production --no-confirm

# Skip health checks
php artisan deploy staging --skip-health-check
```

**What happens during deployment:**

1. ✅ Health checks (disk space, memory)
2. 🔒 Lock deployment
3. 📦 Create new release directory
4. 🏗️ Build assets locally (`npm run build`)
5. 📤 Sync files to server via rsync
6. 🔗 Link shared directories (storage, .env)
7. 📥 Install composer dependencies
8. 🗄️ Run database migrations
9. ⚡ Optimize (cache config, views, routes)
10. 🔄 Restart services (PHP-FPM, Nginx)
11. ✨ Symlink to new release
12. 🧹 Cleanup old releases
13. 🔓 Unlock deployment

### Rollback

```bash
# Rollback to previous release
php artisan deploy:rollback production

# Skip confirmation
php artisan deploy:rollback staging --no-confirm
```

### Database Operations

**Backup Database:**

```bash
# Backup database on server
php artisan database:backup production

# Interactive server selection
php artisan database:backup --select
```

**Download Backup:**

```bash
# Download latest backup
php artisan database:download production

# Interactive selection
php artisan database:download --select
```

**Upload Backup:**

```bash
# Upload backup to server
php artisan database:upload backup-file.sql --target=user@server
```

**Restore Database:**

```bash
# Restore from downloaded backup
php artisan database:restore --latest
```

## 📖 Advanced Configuration

### Rsync Exclusions

Edit `.deploy/deploy.yaml` to customize what gets deployed:

```yaml
config:
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

### Health Check Endpoints

Configure health check endpoints:

```yaml
hosts:
  production:
    hostname: yourapp.com
    # ... other config
    health_check_endpoints:
      - url: https://yourapp.com/health
        status: 200
      - url: https://yourapp.com/api/status
        status: 200
```

### Notifications

Set environment variables for notifications:

```env
# Slack
DEPLOY_SLACK_WEBHOOK=https://hooks.slack.com/services/YOUR/WEBHOOK/URL

# Discord
DEPLOY_DISCORD_WEBHOOK=https://discord.com/api/webhooks/YOUR/WEBHOOK/URL
```

### Custom Post-Deployment Hooks

Create `.dep/post-deploy.sh` on your server:

```bash
#!/bin/bash
# Run custom tasks after deployment
echo "Running custom post-deployment tasks..."

# Example: Clear application cache
php artisan cache:clear

# Example: Restart custom services
sudo systemctl restart your-custom-service
```

## 🔧 Common Tasks

### First Deployment

```bash
# 1. Ensure server is prepared
ssh deploy@yourserver.com "mkdir -p /var/www/app"

# 2. Deploy application
php artisan deploy staging

# 3. SSH to server and create .env
ssh deploy@yourserver.com
cd /var/www/app/current
nano .env  # Add your production environment variables
exit

# 4. Deploy again to apply configuration
php artisan deploy staging
```

### View Releases

```bash
# SSH to your server
ssh deploy@yourserver.com

# List releases
ls -la /var/www/app/releases/

# Current release
ls -la /var/www/app/current
```

### Manual Rollback

If needed, you can manually rollback:

```bash
# SSH to server
ssh deploy@yourserver.com

# List releases
cd /var/www/app/releases
ls -t

# Symlink to previous release
ln -nfs /var/www/app/releases/202501.2 /var/www/app/current
```

## 🐛 Troubleshooting

### SSH Connection Issues

```bash
# Test SSH connection
ssh deploy@yourserver.com

# Verify SSH key is added
ssh-add -l

# Add SSH key if needed
ssh-add ~/.ssh/id_ed25519
```

### Permission Issues

```bash
# On server, ensure correct ownership
sudo chown -R deploy:deploy /var/www/app

# Ensure writable directories
chmod -R 775 /var/www/app/shared/storage
```

### Deployment Locked

If deployment is stuck locked:

```bash
# SSH to server
ssh deploy@yourserver.com

# Remove lock file
rm /var/www/app/.dep/deploy.lock
```

### Rsync Issues

```bash
# Ensure rsync is installed locally
which rsync

# Ensure rsync is installed on server
ssh deploy@yourserver.com "which rsync"

# Install if missing (Ubuntu/Debian)
sudo apt-get install rsync
```

## 📚 Architecture

This package uses a **simple, cohesive action-based architecture**:

**Actions** (Complete workflows):
- `DeployAction` - Full deployment process
- `RollbackAction` - Rollback to previous release
- `DatabaseAction` - Database operations
- `HealthCheckAction` - Health verification
- `OptimizeAction` - Cache & service optimization
- `NotificationAction` - Deployment notifications

**Services** (Core functionality):
- `CommandService` - Execute local/remote commands
- `DeploymentService` - Release & lock management
- `ConfigService` - Configuration loading
- `RsyncService` - File synchronization

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## 🙏 Credits

- Built with [Spatie SSH](https://github.com/spatie/ssh)
- Inspired by [Deployer](https://deployer.org/)
- Architecture follows **SIMPLICITY over complexity**

## 💡 Tips

- **Always test deployments on staging first**
- **Backup your database before major updates**
- **Use `--no-confirm` in CI/CD pipelines**
- **Monitor the first few deployments closely**
- **Set up health check endpoints for critical apps**

---

**Ready to deploy?** Run `php artisan deploy staging` and watch the magic happen! ✨

For more details, see the [documentation](docs/) or [open an issue](https://github.com/yourusername/laravel-deployer/issues).
