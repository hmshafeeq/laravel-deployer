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
- 🔑 **SSH Key Generator** - Interactive key generation and server setup
- 🖥️ **Server Provisioning** - Automated LEMP stack setup with security hardening
- 🎨 **Beautiful Output** - Clear, colored progress indicators
- 📢 **Notifications** - Slack and Discord integration
- 🔍 **Diff Display** - See exactly what files will be deployed with color-coded changes
- ✅ **Confirmation Prompts** - Prevent accidents with configurable confirmation before deployment

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

## 🖥️ Server Provisioning

Laravel Deployer includes a comprehensive server provisioning system that automatically sets up a fresh Ubuntu server with everything needed to run Laravel applications.

### Quick Provision

The simplest way to provision a server (interactive mode):

```bash
php artisan laravel-deployer:provision
```

This will guide you through:
1. Server connection details (host, port, SSH credentials)
2. User creation options (use default ubuntu user or create a deployment user)
3. Software versions (PHP, Node.js)
4. Database selection (MySQL, PostgreSQL, Redis)
5. Additional features (Supervisor, Firewall, Swap)

### Non-Interactive Provisioning

For automation or CI/CD pipelines:

```bash
php artisan laravel-deployer:provision \
    --host=your-server.com \
    --user=ubuntu \
    --key=/path/to/ssh/key \
    --create-user \
    --deploy-user=deployer \
    --php-version=8.3 \
    --nodejs-version=20 \
    --with-mysql \
    --with-redis \
    --non-interactive
```

### What Gets Installed

#### Core Components
- **Nginx**: Web server with optimized configuration for Laravel
- **PHP**: With extensions (FPM, CLI, MySQL, PostgreSQL, Redis, cURL, GD, mbstring, XML, Zip, BCMath, SOAP, Intl, OPcache, Imagick)
- **Node.js**: With npm, Yarn, and PM2
- **Composer**: Latest version with global configuration

#### Databases (Optional)
- **MySQL**: With secure installation and performance tuning
- **PostgreSQL**: With password authentication configured
- **Redis**: With memory management and LRU eviction policy

#### Security Features
- **UFW Firewall**: Configured with ports 22 (SSH), 80 (HTTP), 443 (HTTPS)
- **Fail2Ban**: Protection against brute-force attacks
- **SSH Hardening**: Strong ciphers, key exchange algorithms, disabled root login
- **Automatic Security Updates**: Unattended upgrades configured

#### Performance Features
- **Swap Space**: Configurable size (1G, 2G, 4G) for servers with limited RAM
- **PHP-FPM**: Optimized pool configuration with proper user permissions
- **OPcache**: Configured for production performance
- **Nginx**: Gzip compression, static file caching, security headers

### Provision Options

| Option | Description | Default |
|--------|-------------|---------|
| `--host` | Server hostname or IP address | (required) |
| `--port` | SSH port | 22 |
| `--user` | SSH user | ubuntu |
| `--password` | SSH password (if not using key) | - |
| `--key` | Path to SSH private key | ~/.ssh/id_rsa |
| `--create-user` | Create a new deployment user | false |
| `--deploy-user` | Name of deployment user to create | deployer |
| `--php-version` | PHP version to install | 8.3 |
| `--nodejs-version` | Node.js version to install | 20 |
| `--with-mysql` | Install MySQL | false |
| `--with-postgresql` | Install PostgreSQL | false |
| `--with-redis` | Install Redis | false |
| `--non-interactive` | Run without prompts | false |

### After Provisioning

Once provisioning is complete:

1. **Download SSH key** (if you created a deployment user):
   ```bash
   scp ubuntu@your-server:/home/deployer/.ssh/id_rsa ./deploy_key
   chmod 600 ./deploy_key
   ```

2. **Update your `.deploy/deploy.yaml`**:
   ```yaml
   hosts:
     production:
       hostname: 'your-server.com'
       remote_user: 'deployer'
       identity_file: './deploy_key'
       deploy_path: '/var/www/production'
   ```

3. **Deploy your application**:
   ```bash
   php artisan laravel-deployer:deploy production
   ```

### Provision Examples

**Basic Setup with Ubuntu User:**
```bash
php artisan laravel-deployer:provision \
    --host=192.168.1.100 \
    --user=ubuntu \
    --key=~/.ssh/id_rsa \
    --php-version=8.3 \
    --with-redis
```

**Full Setup with Deployment User:**
```bash
php artisan laravel-deployer:provision \
    --host=production.example.com \
    --create-user \
    --deploy-user=deployer \
    --php-version=8.3 \
    --nodejs-version=20 \
    --with-mysql \
    --with-redis
```

### Supported Ubuntu Versions

- Ubuntu 24.04 LTS (Noble Numbat)
- Ubuntu 22.04 LTS (Jammy Jellyfish)
- Ubuntu 20.04 LTS (Focal Fossa)

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
# Option 1: Use the built-in key generator (recommended)
php artisan deploy:key-generate deploy@yourapp.com

# Option 2: Generate SSH key manually
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
5. 🔍 **Show sync differences** (new, modified, deleted files)
6. ✅ **Confirm changes** before uploading
7. 📤 Sync files to server via rsync
8. 🔗 Link shared directories (storage, .env)
9. 📥 Install composer dependencies
10. 🗄️ Run database migrations
11. ⚡ Optimize (cache config, views, routes)
12. 🔄 Restart services (PHP-FPM, Nginx)
13. ✨ Symlink to new release
14. 🧹 Cleanup old releases
15. 🔓 Unlock deployment

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

### Deployment Diff & Confirmation

Control whether to show file differences and require confirmation before deployment:

```yaml
config:
  # Diff and confirmation settings
  show_diff: true                    # Show files that will be synced before deployment
  confirm_changes: true               # Ask for confirmation before uploading changes
  show_upload_progress: true          # Show upload progress messages
  diff_display_limit: 20             # Maximum number of files to display per category
```

**Features:**
- **Beautiful Diff Display**: Shows exactly which files will be added, modified, or deleted before deployment
- **Color-Coded Output**:
  - 🟢 Green for new files
  - 🟡 Yellow for modified files
  - 🔴 Red for deleted files
- **Smart Categorization**: Groups changes by type with file counts
- **Confirmation Prompts**: Prevents accidental deployments by requiring user confirmation
- **Configurable Display Limit**: Control how many files are shown per category to avoid overwhelming output
- **Production Safety**: Extra warnings when deploying file deletions to production

**Configuration Options:**
- `show_diff: true|false` - Enable or disable diff display (default: `true`)
- `confirm_changes: true|false` - Require confirmation after showing diff (default: `true`)
- `show_upload_progress: true|false` - Show upload progress indicators (default: `true`)
- `diff_display_limit: N` - Maximum files to show per category (default: `20`)

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

### Generate SSH Keys

Use the built-in SSH key generator to create and configure keys for deployment:

```bash
# Generate new SSH key
php artisan deploy:key-generate deploy@yourapp.com

# Generate with custom name
php artisan deploy:key-generate deploy@yourapp.com --name=deploy_key

# Force generation without prompting
php artisan deploy:key-generate deploy@yourapp.com --force
```

**What it does:**
- ✅ Detects existing SSH keys
- ✅ Generates new key pairs (RSA 4096-bit)
- ✅ Shows interactive menu for existing keys
- ✅ Displays public key for copying
- ✅ Optionally copies key to server via `ssh-copy-id`
- ✅ Provides helpful setup instructions
- ✅ Clipboard support (Linux, macOS, Windows)

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

---

*Last synced from westwindsupplies monorepo - testing bi-directional sync workflow*
