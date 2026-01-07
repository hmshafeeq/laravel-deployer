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
- ❤️ **Health Checks** - Pre and post-deployment verification with retries
- 🔑 **SSH Key Generator** - Interactive key generation and server setup
- 🖥️ **Server Provisioning** - Automated LEMP stack setup with security hardening
- 🎨 **Beautiful Output** - Clear, colored progress indicators
- 📢 **Notifications** - Slack and Discord integration
- 🔍 **Diff Display** - See exactly what files will be deployed with color-coded changes
- ✅ **Confirmation Prompts** - Prevent accidents with configurable confirmation before deployment
- 🧪 **Dry-Run Mode** - Preview deployment plan without executing
- 📋 **Deployment Receipts** - JSON audit trail for every deployment
- 🔗 **Environment Inheritance** - Reduce config duplication with `extends`
- 🎛️ **Interactive Mode** - Step-by-step prompts for deployment options
- 📊 **Progress Bar** - Real-time file sync progress with ETA
- 📈 **Summary Dashboard** - Beautiful deployment completion summary
- 🪝 **Deployment Hooks** - Custom commands at any deployment step

## 📋 Requirements

- PHP 8.2+
- Laravel 11.x or 12.x
- SSH access to your server
- `rsync` installed locally and on server

## 🚀 Installation

### 1. Install via Composer

```bash
composer require shaf/laravel-deployer --dev
```

> **Note:** This package is designed as a **dev dependency**. It runs on your local machine (or CI/CD) and deploys to servers via SSH. The package is NOT needed on the production server.

### 2. Run Installation Command

```bash
php artisan laravel-deployer:install
```

This creates:
- `.deploy/deploy.json` - Deployment configuration (tracked in git)
- `.deploy/.env.{environment}.example` - Secret templates (gitignored)
- `.gitignore` entries to track `deploy.json` but ignore `.env.*` files

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

2. **Update your `.deploy/deploy.json`** and `.deploy/.env.production`:
   ```json
   {
     "environments": {
       "production": {
         "deployPath": "/var/www/production"
       }
     }
   }
   ```

   In `.deploy/.env.production`:
   ```env
   DEPLOY_HOST=your-server.com
   DEPLOY_USER=deployer
   DEPLOY_IDENTITY_FILE=./deploy_key
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

## 🔄 Migrating Existing Sites

If you have an existing Laravel deployment and want to use laravel-deployer, use the `deployer:migrate` command to convert your directory structure.

> **Prerequisites**: Your site must be using a **traditional flat deployment** (`/var/www/domain.com/public`) with nginx config pointing directly to the public folder. Sites already using `releases/` and `current` symlinks are already migrated.

### Quick Migration

```bash
# Migrate staging environment (uses deploy.json configuration)
php artisan deployer:migrate staging

# Migrate production
php artisan deployer:migrate production

# Dry run first (see what would happen)
php artisan deployer:migrate staging --dry-run

# Skip confirmation prompts
php artisan deployer:migrate staging --force
```

### Alternative: Shell Script

For advanced use cases, you can also use the shell script directly:

```bash
./vendor/shaf/laravel-deployer/scripts/migrate-to-deployer.sh ubuntu@server.com example.com
```

### What It Does

1. **Backs up project files** to `/var/www/backups/{domain}-files-{timestamp}.tar.gz`
   - Includes hidden files (`.env`, `.htaccess`, etc.)
   - Excludes `vendor/`, `node_modules/`, `.git/`
2. **Backs up database** to `/var/www/backups/{domain}-database-{timestamp}.sql.gz` (auto-detects credentials from .env)
3. **Only proceeds** after both backups succeed
4. **Creates** the releases/shared directory structure
5. **Moves** files to first release (named `YYYYMM.1`, e.g., `202512.1`)
6. **Sets** proper permissions for deploy and web users

### Usage Options

```bash
./migrate-to-deployer.sh <host> <domain> [options]

# Options:
#   --user=USER        SSH user (default: ubuntu)
#   --key=PATH         SSH private key path
#   --base-path=PATH   Site base path (default: /var/www)
#   --skip-db-backup   Skip database backup
#   --dry-run          Show what would happen

# Examples:
./migrate-to-deployer.sh ubuntu@192.168.1.100 thepayrollapp.com
./migrate-to-deployer.sh server.com dev.example.com --key=~/.ssh/deploy_key
```

### Post-Migration

Update your nginx config to use the `current` symlink:

```nginx
# Change from:
root /var/www/example.com/public;

# To:
root /var/www/example.com/current/public;
```

Then reload nginx:
```bash
sudo nginx -t && sudo systemctl reload nginx
```

📖 See [docs/migration-script.md](docs/migration-script.md) for detailed documentation.

## ⚙️ Configuration

### Basic Setup

**1. Edit `.deploy/deploy.json`:**

```json
{
  "$schema": "./vendor/shaf/laravel-deployer/stubs/deploy.schema.json",

  "keepReleases": 3,

  "composer": {
    "options": "--prefer-dist --no-interaction --optimize-autoloader"
  },

  "environments": {
    "staging": {
      "deployPath": "/var/www/staging"
    },
    "production": {
      "deployPath": "/var/www/production",
      "composer": {
        "options": "--prefer-dist --no-interaction --no-dev --optimize-autoloader"
      }
    }
  },

  "postDeploy": [
    "config:cache",
    "route:cache"
  ]
}
```

**2. Create environment secrets:**

```bash
# Copy example files
cp .deploy/.env.staging.example .deploy/.env.staging
cp .deploy/.env.production.example .deploy/.env.production

# Edit with your server credentials
nano .deploy/.env.staging
```

**Example `.env.staging`:**

```env
DEPLOY_HOST=staging.yourapp.com
DEPLOY_USER=deploy
DEPLOY_IDENTITY_FILE=~/.ssh/id_ed25519
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

# Preview deployment without executing (dry-run)
php artisan deploy production --dry-run
```

### Dry-Run Mode

Preview what would happen during deployment without actually executing any commands:

```bash
php artisan deploy staging --dry-run
```

**Output example:**
```
╔══════════════════════════════════════════════════════════════╗
║                    DRY RUN - No changes made                  ║
╠══════════════════════════════════════════════════════════════╣
║ Environment:  staging                                         ║
║ Server:       staging.example.com                             ║
║ Deploy Path:  /var/www/staging                                ║
╠══════════════════════════════════════════════════════════════╣
║                   Deployment Steps                            ║
╠══════════════════════════════════════════════════════════════╣
║  1. Lock deployment           Prevent concurrent deployments  ║
║  2. Create release directory  202501.X (auto-generated)       ║
║  3. Build frontend assets     npm run build                   ║
║  4. Calculate file diff       Compare local → server          ║
║  5. Sync files via rsync      Upload changed files            ║
║  ...                                                          ║
╠══════════════════════════════════════════════════════════════╣
║                    Files to Deploy                            ║
╠══════════════════════════════════════════════════════════════╣
║   + 5 new files                                               ║
║   ~ 12 modified files                                         ║
║   - 2 deleted files                                           ║
╚══════════════════════════════════════════════════════════════╝
```

Use dry-run to:
- Preview deployments before executing
- Verify configuration is correct
- Review file changes that would be synced

### Interactive Mode

Interactive mode allows you to configure each deployment option through prompts:

```bash
php artisan deploy staging --interactive
```

**Output example:**
```
═══════════════════════════════════════════════════════════
                   INTERACTIVE MODE
═══════════════════════════════════════════════════════════

  Environment: staging
  Server:      staging.example.com

  Configure your deployment options below:

  Build frontend assets locally? [Y/n] Y
  Run database migrations? [Y/n] Y
  Clear Laravel caches after deployment? [Y/n] Y
  Optimize application (config:cache, route:cache)? [Y/n] Y
  Show file changes before uploading? [Y/n] Y
  Require confirmation before uploading? [Y/n] n

═══════════════════════════════════════════════════════════

  Selected options:

    ✓ Build assets
    ✓ Run migrations
    ✓ Clear caches
    ✓ Optimize app
    ✓ Show diff
    ✗ Confirm changes

  Proceed with deployment? [Y/n]
```

### Progress Bar

During file sync, a progress bar shows real-time upload status:

```
[staging] [████████████████████░░░░░░░░░░] 67% (85/127 files) ETA: 12s
```

### Deployment Summary Dashboard

After successful deployment, a summary dashboard is displayed:

```
╔════════════════════════════════════════════════════════════╗
║                    DEPLOYMENT COMPLETE                      ║
╠════════════════════════════════════════════════════════════╣
║ Environment:  staging                                       ║
║ Release:      202501.4                                      ║
║ Duration:     45.2s                                         ║
║ Files:        +5 ~12 -2 (19 total)                          ║
║ URL:          https://staging.example.com                   ║
╚════════════════════════════════════════════════════════════╝
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

### Deployment Receipts

Every successful deployment generates a JSON receipt for audit trails and debugging. Receipts are stored on the server at `.dep/receipts/{release}.json`.

**Receipt structure:**
```json
{
  "release": "202501.5",
  "environment": "staging",
  "deployed_at": "2025-01-27T14:30:00+00:00",
  "deployed_by": "john",
  "duration_seconds": 45.2,
  "git": {
    "commit": "abc123def456",
    "branch": "main",
    "message": "feat: add user authentication"
  },
  "stats": {
    "files_synced": 127,
    "files_added": 5,
    "files_modified": 12,
    "files_deleted": 2,
    "bytes_transferred": 3355443
  },
  "post_deploy_commands": ["config:cache", "route:cache"],
  "success": true
}
```

**Use receipts to:**
- Track who deployed what and when
- Debug deployment issues with git commit info
- Monitor deployment duration and file changes
- Create deployment history reports

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

### Environment Inheritance

Environments can inherit configuration from other environments using the `extends` key. This reduces duplication when environments share similar settings:

```json
{
  "environments": {
    "production": {
      "deployPath": "/var/www/production",
      "composer": {
        "options": "--prefer-dist --no-interaction --no-dev --optimize-autoloader"
      }
    },
    "staging": {
      "extends": "production",
      "deployPath": "/var/www/staging"
    }
  }
}
```

In this example, `staging` inherits all settings from `production` but overrides `deployPath`. The staging environment will use production's composer options.

**Inheritance rules:**
- Child environments inherit all settings from parent
- Child settings override parent settings
- Deep merging is performed for nested objects
- Circular inheritance is detected and prevented

### Post-Deployment Health Check

Verify your application is responding correctly after deployment:

```json
{
  "healthCheck": {
    "enabled": true,
    "url": "/health",
    "timeout": 10,
    "expectedStatus": 200,
    "retries": 3,
    "retryDelay": 2
  }
}
```

**Configuration options:**
- `enabled` - Enable/disable post-deployment health check (default: `false`)
- `url` - Health check endpoint (relative path or full URL)
- `timeout` - Request timeout in seconds (default: `10`)
- `expectedStatus` - Expected HTTP status code (default: `200`)
- `retries` - Number of retry attempts (default: `3`)
- `retryDelay` - Delay between retries in seconds (default: `2`)

**What happens:**
1. After the symlink swap, the deployer waits briefly for the app to initialize
2. Makes an HTTP request to the health check URL
3. Retries if the check fails (up to configured retries)
4. Deployment is marked successful only if health check passes

### Deployment Hooks

Define custom commands to run at specific points during deployment:

```json
{
  "hooks": {
    "before:deploy": ["local:git fetch --all"],
    "after:setup": [],
    "before:build": ["local:npm ci"],
    "after:build": [],
    "before:sync": [],
    "after:sync": ["artisan storage:link"],
    "before:composer": [],
    "after:composer": ["artisan package:discover"],
    "before:migrate": ["artisan backup:run --only-db"],
    "after:migrate": [],
    "before:symlink": [],
    "after:symlink": ["artisan horizon:terminate", "artisan queue:restart"],
    "after:deploy": ["notify:slack"],
    "on:failure": ["artisan cache:clear"]
  }
}
```

**Hook points:**

| Hook | When it runs |
|------|-------------|
| `before:deploy` | Before deployment starts (pre-lock) |
| `after:setup` | After deployment structure is created |
| `before:build` | Before frontend assets are built |
| `after:build` | After frontend assets are built |
| `before:sync` | Before files are synced to server |
| `after:sync` | After files are synced to server |
| `before:composer` | Before composer install runs |
| `after:composer` | After composer install runs |
| `before:migrate` | Before database migrations run |
| `after:migrate` | After database migrations run |
| `before:symlink` | Before release is symlinked as current |
| `after:symlink` | After release is symlinked as current |
| `after:deploy` | After deployment completes successfully |
| `on:failure` | When deployment fails |

**Command prefixes:**
- `artisan <command>` - Run a Laravel Artisan command on the server
- `local:<command>` - Run a command locally (not on server)
- No prefix - Run a shell command on the server in the release directory

**Example use cases:**
- **`after:symlink`**: Restart queue workers, terminate Horizon
- **`before:migrate`**: Create database backup before migrations
- **`on:failure`**: Clean up caches, send failure notification
- **`before:build`**: Ensure dependencies are installed locally

### Deployment Diff & Confirmation

Control whether to show file differences and require confirmation before deployment:

```json
{
  "display": {
    "showDiff": true,
    "confirmChanges": true,
    "showUploadProgress": true,
    "diffDisplayLimit": 20
  }
}
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
- `showDiff: true|false` - Enable or disable diff display (default: `true`)
- `confirmChanges: true|false` - Require confirmation after showing diff (default: `true`)
- `showUploadProgress: true|false` - Show upload progress indicators (default: `true`)
- `diffDisplayLimit: N` - Maximum files to show per category (default: `20`)

### Rsync Exclusions

Edit `.deploy/deploy.json` to customize what gets deployed:

```json
{
  "rsync": {
    "exclude": [
      ".git/",
      "node_modules/",
      ".env",
      "storage/",
      "tests/"
    ],
    "include": [
      "composer.json",
      "composer.lock"
    ]
  }
}
```

### Notifications

Set environment variables for notifications:

```env
# Slack
DEPLOY_SLACK_WEBHOOK=https://hooks.slack.com/services/YOUR/WEBHOOK/URL

# Discord
DEPLOY_DISCORD_WEBHOOK=https://discord.com/api/webhooks/YOUR/WEBHOOK/URL
```

### Post-Deployment Commands

Configure artisan commands to run after deployment in `.deploy/deploy.json`:

```json
{
  "postDeploy": [
    "config:cache",
    "route:cache",
    "view:cache",
    "icons:cache"
  ]
}
```

### Custom Post-Deployment Shell Script

Create `.dep/post-deploy.sh` on your server for advanced tasks:

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
- `DiffAction` - File diff calculation and display
- `DatabaseAction` - Database operations
- `HealthCheckAction` - Pre and post-deployment health verification
- `OptimizeAction` - Cache & service optimization
- `NotificationAction` - Deployment notifications

**Services** (Core functionality):
- `CommandService` - Execute local/remote commands
- `DeploymentService` - Release & lock management
- `ConfigService` - Configuration loading with environment inheritance
- `RsyncService` - File synchronization
- `ReceiptService` - Deployment receipt generation and storage

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

*Last synced from timebox monorepo - testing bi-directional sync workflow*
