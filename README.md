# Laravel Deployer

A comprehensive Laravel package that provides deployment automation using Deployer PHP with pre-configured tasks, recipes, and workflows optimized for Laravel applications.

> **Built on top of [Deployer](https://github.com/deployphp/deployer)** - A deployment tool written in PHP with support for popular frameworks out of the box. Major credits to the Deployer team for creating such an excellent deployment foundation.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Deployment Workflows](#deployment-workflows)
- [Available Tasks](#available-tasks)
- [Database Management](#database-management)
- [Health & Monitoring](#health--monitoring)
- [Log Management](#log-management)
- [Rollback Operations](#rollback-operations)
- [Advanced Usage](#advanced-usage)
- [Task Architecture](#task-architecture)
- [Similar Projects](#similar-projects)
- [License](#license)

## Features

- 🚀 **Quick Setup**: Get deployment ready in minutes with `php artisan laravel-deployer:install`
- 📦 **Pre-configured Tasks**: Database backups, health checks, resource monitoring, and more
- 🔔 **Smart Notifications**: Desktop notifications for deployment success/failure (macOS, Linux, Windows)
- 📊 **Health Monitoring**: Comprehensive endpoint testing and server resource checks
- 💾 **Database Management**: Automated backups with download capabilities and restoration
- 📝 **Log Analysis**: Automated log checking for errors and warnings with remote access
- ⚡ **Flexible Deployment**: Choose quick deploy (fast) or full deploy (with database backup)
- 🔄 **Smart Rollbacks**: Quick rollback (code only) or full rollback (with database restore)
- 🎯 **Multiple Environments**: Support for local, staging, and production deployments
- 🔐 **Secure Configuration**: Environment-specific credentials stored in gitignored `.deploy/` directory
- ⚙️ **Customizable Workflows**: Easy to extend and customize deployment tasks

## Requirements

- PHP ^8.2
- Laravel ^11.0|^12.0
- Deployer ^7.0
- Node.js & npm (for asset building)
- SSH access to deployment servers
- MySQL/MariaDB database

## Installation

### Step 1: Add the Package

Add the package to your Laravel project's `composer.json`:

```bash
composer require --dev shaf/laravel-deployer
```

### Step 2: Run Installation Command

Generate the deployment configuration files:

```bash
php artisan laravel-deployer:install
```

This command will:
- Generate `deploy.yaml` configuration file
- Create `.deploy/` directory with environment-specific example files
- Update `.gitignore` to exclude sensitive deployment credentials
- Display next steps and available commands

## Quick Start

### 1. Configure Your Environments

Copy the example environment files and configure them:

```bash
# For staging environment
cp .deploy/.env.staging.example .deploy/.env.staging

# For production environment
cp .deploy/.env.production.example .deploy/.env.production

# For local testing (optional)
cp .deploy/.env.local.example .deploy/.env.local
```

Edit the `.env.*` files with your actual server details:

```bash
# .deploy/.env.staging
DEPLOY_HOST=staging.yourapp.com
DEPLOY_USER=deploy
DEPLOY_PATH=/var/www/staging
DEPLOY_BRANCH=dev
```

### 2. Run Your First Deployment

```bash
# Quick deploy to staging (without database backup)
vendor/bin/dep deploy staging

# Full deploy to production with database backup (recommended for production)
vendor/bin/dep deploy:full production
```

## Configuration

### Deploy Configuration (`deploy.yaml`)

The main configuration file supports:

```yaml
config:
    application: 'Your App Name'
    ssh_multiplexing: true
    keep_releases: 3
    default_timeout: 900
    default_selector: staging
    log_files: ['storage/logs/*.log']

hosts:
    staging:
        hostname: 'staging.yourapp.com'
        remote_user: 'deploy'
        deploy_path: '/var/www/staging'
        branch: 'dev'
    
    production:
        hostname: 'production.yourapp.com'
        remote_user: 'deploy'
        deploy_path: '/var/www/production'
        branch: 'main'
```

### Environment Files (`.deploy/` directory)

**`.deploy/.env.staging`**:
```bash
DEPLOY_HOST=staging.yourapp.com
DEPLOY_USER=deploy
DEPLOY_PATH=/var/www/staging
DEPLOY_BRANCH=dev
```

**`.deploy/.env.production`**:
```bash
DEPLOY_HOST=production.yourapp.com
DEPLOY_USER=deploy
DEPLOY_PATH=/var/www/production
DEPLOY_BRANCH=main
```

### Rsync Configuration

Fine-tune file synchronization with rsync options in `deploy.yaml`:

```yaml
config:
    rsync:
        exclude:
            - .git/
            - node_modules/
            - vendor/
            - storage/
            - tests/
            - .env
        include:
            - composer.json
            - composer.lock
        flags: 'rzc'
        options:
            - delete
            - compress
        timeout: 900
```

## Deployment Workflows

### Quick Deployment (Default)

Fast deployment without database backup (useful for staging/development):

```bash
vendor/bin/dep deploy staging
vendor/bin/dep deploy production
```

**Workflow includes:**
1. Environment configuration loading
2. Deployment confirmation prompt
3. Server resource checks (disk, memory)
4. Build assets locally (`npm run build`)
5. Rsync files to server
6. Install Composer dependencies
7. Run Laravel optimizations (config, view, route cache)
8. Run migrations
9. Restart services (PHP-FPM, queue workers, Supervisor)
10. Health checks and endpoint tests
11. Desktop notification on completion

### Full Deployment (With Database Backup)

Complete deployment with automatic database backup (recommended for production):

```bash
vendor/bin/dep deploy:full staging
vendor/bin/dep deploy:full production
```

**Same as quick deployment plus:**
- **Automatic database backup** before migrations (production safety)

### Local Deployment

Deploy to local filesystem for testing:

```bash
vendor/bin/dep deploy local
```

**Optimized for local environment:**
- Skips service restarts
- Uses local filesystem instead of SSH
- Auto-creates `.env` file from project root
- Uses SQLite by default

## Available Tasks

### Core Deployment Tasks

| Task | Description |
|------|-------------|
| `deploy` | Quick deployment without database backup (default) |
| `deploy:full` | Full deployment with automatic database backup |
| `deploy:info` | Display deployment information |
| `deploy:setup` | Initialize deployment structure on server |
| `deploy:lock` | Lock deployment to prevent concurrent runs |
| `deploy:unlock` | Unlock deployment |

> **💡 Quick Reference:**
> - Use `deploy` for fast deployments to staging/development
> - Use `deploy:full` for production deployments with automatic database backup

### Database Management Tasks

| Task | Description |
|------|-------------|
| `database:backup` | Create compressed database backup (keeps last 3) |
| `database:download` | Download database backup to local machine |
| `database:restore` | Restore database from backup (local only) |

**Database Backup Example:**
```bash
# Create backup on server
vendor/bin/dep database:backup production

# Download latest backup
vendor/bin/dep database:download production

# Download specific backup (non-interactive)
DEPLOYER_BACKUP_SELECTION=2 vendor/bin/dep database:download production

# Use SCP for faster download
DEPLOYER_DOWNLOAD_METHOD=scp vendor/bin/dep database:download production
```

### Health & Monitoring Tasks

| Task | Description |
|------|-------------|
| `health:check-resources` | Check server resources (disk space, memory) |
| `health:check-endpoints` | Test critical endpoints and health status |

**Health Check Example:**
```bash
vendor/bin/dep health:check-endpoints production
```

Output includes:
- `/health` endpoint JSON response
- HTTP status for `/`, `/admin/login`, `/user/login`
- Retry logic for temporary failures

### Log Management Tasks

| Task | Description |
|------|-------------|
| `logs:check` | Analyze last 7 days for errors and warnings |
| `logs:list` | List available log files on server |
| `logs:view` | View a specific log file |
| `logs:search` | Search log file for specific terms |
| `logs:download` | Download log file to local machine |

**Log Management Examples:**
```bash
# Check recent logs for issues
vendor/bin/dep logs:check production

# View latest 50 lines
vendor/bin/dep logs:view production --lines=50

# Follow log in real-time
vendor/bin/dep logs:view production --follow

# Search for errors
vendor/bin/dep logs:search production --search="ERROR" --lines=100

# Download log file
vendor/bin/dep logs:download production --destination=./logs/
```

### Rollback Tasks

| Task | Description |
|------|-------------|
| `rollback` | Standard Deployer rollback |
| `rollback:quick` | Quick rollback without database restore |
| `rollback:full` | Full rollback including database restoration |

**Rollback Examples:**
```bash
# Quick rollback (code only)
vendor/bin/dep rollback:quick production

# Full rollback (code + database)
vendor/bin/dep rollback:full production
```

### Service Management Tasks

| Task | Description |
|------|-------------|
| `php-fpm:restart` | Restart PHP-FPM service |
| `supervisor:reload` | Reload Supervisor configuration |
| `artisan:queue:restart` | Restart Laravel queue workers |

### Utility Tasks

| Task | Description |
|------|-------------|
| `build:assets` | Build frontend assets with npm |
| `cleanup:old-releases` | Remove old releases (keeps last 3) |
| `notify:success` | Send success notification |
| `notify:failure` | Send failure notification |

## Database Management

### Automated Backups

**During Full Deployment:**
```bash
# Full deployment includes automatic backup before migrations
vendor/bin/dep deploy:full production
```

**Manual Backup:**
```bash
vendor/bin/dep database:backup production
```

**Features:**
- Compressed SQL backups with gzip
- Automatic cleanup (keeps last 3 backups)
- Timestamp-based naming: `db_backup_2024-03-15_14-30-45.sql.gz`
- Size reporting and verification
- Secure credential handling with temporary config files

### Backup Download

**Interactive Download:**
```bash
vendor/bin/dep database:download production
```

**Non-Interactive Download:**
```bash
# Download latest backup
DEPLOYER_BACKUP_SELECTION=latest vendor/bin/dep database:download production

# Download specific backup by number
DEPLOYER_BACKUP_SELECTION=2 vendor/bin/dep database:download production

# Use SCP for faster transfer
DEPLOYER_DOWNLOAD_METHOD=scp vendor/bin/dep database:download production
```

**Download Features:**
- Progress monitoring (every 30 seconds)
- Size verification
- Speed calculation
- Resume capability (with rsync method)
- Automatic cleanup on failure

### Database Restoration

**Restore from Local Backup:**
```bash
# Via Laravel Artisan
php artisan database:restore db_backup_2024-03-15_14-30-45.sql.gz

# Via Deployer (prompts for backup selection)
vendor/bin/dep database:restore local
```

## Health & Monitoring

### Resource Checks

Monitor server resources before deployment:

```bash
vendor/bin/dep health:check-resources production
```

**Checks:**
- Disk usage and available space
- Memory usage (RAM and Swap)
- Warnings at 80% disk usage
- Errors at 90% disk usage

### Endpoint Testing

Verify application health after deployment:

```bash
vendor/bin/dep health:check-endpoints production
```

**Tests:**
- Dedicated `/health` endpoint with JSON response
- Critical pages: `/`, `/admin/login`, `/user/login`
- Retry logic (3 attempts with 5-second delays)
- Accepts 200, 302, 401 as valid responses

**Sample Health Endpoint Response:**
```json
{
    "status": "healthy",
    "timestamp": "2024-03-15T14:30:45Z",
    "services": {
        "database": "ok",
        "cache": "ok",
        "queue": "ok"
    }
}
```

## Log Management

### Log Analysis

Analyze application logs for issues:

```bash
vendor/bin/dep logs:check production
```

**Output Example:**
```
📊 Checking application logs (last 7 days)...

📅 2024-03-15: 🔴 3 errors, 🟡 5 warnings, ℹ️  120 info
📅 2024-03-14: ✅ No errors or warnings, ℹ️  98 info

📈 Summary (Last 7 days):
   🔴 Total Errors: 3
   🟡 Total Warnings: 5
   ℹ️  Total Info: 1,245

🚨 Recent Issues:
   🔴 [2024-03-15] SQLSTATE[HY000]: General error
   🟡 [2024-03-15] Slow query detected (2.5s)
```

### Remote Log Access

**List Available Logs:**
```bash
vendor/bin/dep logs:list production
```

**View Log File:**
```bash
# View last 20 lines (default)
vendor/bin/dep logs:view production

# View last 100 lines
vendor/bin/dep logs:view production --lines=100

# View all lines
vendor/bin/dep logs:view production --lines=0

# Follow log in real-time
vendor/bin/dep logs:view production --follow
```

**Search Logs:**
```bash
# Interactive search
vendor/bin/dep logs:search production

# Direct search
vendor/bin/dep logs:search production --search="ERROR" --lines=50

# Case-sensitive search
vendor/bin/dep logs:search production --search="CRITICAL"
```

**Download Logs:**
```bash
# Interactive download
vendor/bin/dep logs:download production

# Specify destination
vendor/bin/dep logs:download production --destination=./logs/production.log
```

## Rollback Operations

### Quick Rollback

Rollback code to previous release without touching the database:

```bash
vendor/bin/dep rollback:quick production
```

**Process:**
1. Clear queue jobs
2. Rollback to previous release
3. Clear Laravel caches (config, view, route)
4. Restart PHP-FPM and queue workers
5. Desktop notification

**Use when:**
- Bug in new code but database is fine
- Need fastest possible rollback
- Database changes are backward compatible

### Full Rollback

Complete rollback including database restoration:

```bash
vendor/bin/dep rollback:full production
```

**Process:**
1. Interactive confirmation prompt
2. Database restoration from backup
3. Code rollback to previous release
4. Service restarts
5. Verification

**Use when:**
- Database migration caused issues
- Need to restore both code and data
- Complete revert to previous state required

### Standard Rollback

Deployer's built-in rollback (code only):

```bash
vendor/bin/dep rollback production
```

## Advanced Usage

### Custom Tasks

Add custom tasks in `deploy.yaml`:

```yaml
tasks:
    'custom:task':
        - run: 'echo "Custom task running"'
    
    'build:assets':
        - run_locally: 'npm run build'
    
    'post-deployment':
        - run: '{{current_path}}/post-deployment.sh'
```

### Task Hooks

Execute tasks before/after other tasks:

```yaml
after:
    deploy:success:
        - custom:notify
        - custom:backup-config
    
    deploy:failed:
        - deploy:unlock
        - notify:failure
```

### Environment-Specific Tasks

```bash
# Run task only on specific environment
vendor/bin/dep custom:task staging
```

### Parallel Execution

Run tasks on multiple hosts:

```bash
# Deploy to all staging servers
vendor/bin/dep deploy staging

# Run health checks on all environments
vendor/bin/dep health:check-endpoints --hosts=staging,production
```

### Debugging

Enable verbose output:

```bash
# Verbose mode
vendor/bin/dep deploy staging -v

# Very verbose mode
vendor/bin/dep deploy staging -vv

# Debug mode
vendor/bin/dep deploy staging -vvv
```

### Override Configuration

Override configuration via environment variables:

```bash
# Override deployment path
DEPLOY_PATH=/var/www/custom vendor/bin/dep deploy staging

# Override branch
DEPLOY_BRANCH=feature/new-ui vendor/bin/dep deploy staging
```

## Task Architecture

### Core Task Files

All deployment tasks are organized in the `tasks/` directory:

#### `tasks/database.php`

**Functions:**
- `getDatabaseConfigWithFile()` - Securely retrieves database credentials
- `selectBackup()` - Interactive or programmatic backup selection
- `downloadWithProgress()` - Monitored file download with progress
- `verifyDownload()` - File integrity verification

**Tasks:**
- `database:backup` - Creates compressed SQL backups
- `database:download` - Downloads backups with progress monitoring

**Features:**
- Secure credential handling with temporary MySQL config files
- Automatic cleanup of old backups (keeps 3 most recent)
- Size verification and error handling
- Multiple download methods (rsync/SCP)
- Progress monitoring with speed calculation

#### `tasks/health.php`

**Tasks:**
- `health:check-resources` - Server resource monitoring
- `health:check-endpoints` - Application endpoint testing

**Features:**
- Disk usage monitoring with warnings/errors
- Memory and swap usage reporting
- HTTP endpoint validation
- Retry logic for transient failures
- JSON health response parsing

#### `tasks/logs.php`

**Functions:**
- `normaliseLogFilesSetting()` - Converts log paths to arrays
- `expandLogFiles()` - Expands wildcard patterns in log paths
- `getLogfileLogsOption()` - Interactive log file selection

**Tasks:**
- `logs:check` - Analyze last 7 days of logs
- `logs:list` - Display available log files
- `logs:view` - View log file with optional following
- `logs:search` - Search logs with grep
- `logs:download` - Download log files

**Features:**
- Multi-day log analysis
- Error/warning/info categorization
- Real-time log following
- Remote log searching
- Wildcard log file support

#### `tasks/notifications.php`

**Functions:**
- `sendNotification()` - Cross-platform desktop notifications

**Tasks:**
- `notify:success` - Success notification
- `notify:failure` - Failure notification

**Features:**
- macOS (osascript)
- Linux (notify-send)
- Windows (PowerShell BurntToast)
- Custom sounds and icons per status

#### `tasks/rollback.php`

**Tasks:**
- `rollback:quick` - Fast code-only rollback
- `rollback:full` - Complete rollback with database

**Features:**
- Queue job clearing before rollback
- Cache clearing after rollback
- Service restart automation
- Interactive confirmation prompts

### Main Recipe File

**`recipe/deploy.php`**

The main orchestration file that:
- Imports Laravel and rsync recipes from Deployer
- Loads all task files
- Defines deployment workflows
- Manages release naming with counters
- Handles environment loading
- Configures task hooks and dependencies

**Key Workflows:**

```php
// Quick deployment (default - no database backup)
task('deploy', [
    'deploy:env',
    'deploy:confirm-target',
    'health:check-resources',
    'build:assets',
    'rsync',
    'deploy:vendors',
    'artisan:migrate',
    'php-fpm:restart',
    'health:check-endpoints',
    'notify:success',
]);

// Full deployment (with database backup before migrations)
task('deploy:full', [
    'deploy:env',
    'deploy:confirm-target',
    'health:check-resources',
    'build:assets',
    'rsync',
    'deploy:vendors',
    'database:backup',  // Database backup added here
    'artisan:migrate',
    'php-fpm:restart',
    'health:check-endpoints',
    'notify:success',
]);
```

### Custom Release Naming

Uses year-month plus incrementing counter:

```php
set('release_name', function () {
    // Generates: 202403.1, 202403.2, etc.
    $yearMonth = date('Ym');
    $counterDir = '{{deploy_path}}/.dep/release_counter';
    $counterFile = "$counterDir/{$yearMonth}.txt";
    
    // Increment and return
    $count = (int)run("cat $counterFile || echo 0") + 1;
    run("echo $count > $counterFile");
    
    return "{$yearMonth}.{$count}";
});
```

### Environment Loading

Automatically loads environment-specific configuration:

```php
task('deploy:env', function () {
    $environment = currentHost()->getAlias();
    $envFile = ".deploy/.env.$environment";
    
    if (file_exists($envFile)) {
        // Load .env file
        $dotenv = Dotenv::createImmutable('.deploy', ".env.$environment");
        $dotenv->load();
        
        // Override host configuration
        if ($host = getenv('DEPLOY_HOST')) {
            currentHost()->set('hostname', $host);
        }
        // ... etc
    }
});
```

### Task Dependencies

Tasks automatically load dependencies:

```php
// Standalone tasks that need environment config
$standaloneTasksRequiringEnv = [
    'database:backup',
    'database:download',
    'logs:check',
    'rollback:quick',
    'rollback:full',
];

foreach ($standaloneTasksRequiringEnv as $task) {
    before($task, 'deploy:env');
}
```

## Similar Projects

While Laravel Deployer provides a comprehensive, opinionated deployment solution built on Deployer PHP, here are other Laravel deployment tools you might consider:

### [omaralalwi/laravel-deployer](https://github.com/omaralalwi/laravel-deployer)
- Laravel-based deployment manager with web UI
- GitHub/GitLab integration
- Built-in CI/CD workflows
- **Best for:** Teams wanting a web-based deployment interface

### [ngocquyhoang/laravel-deploy](https://github.com/ngocquyhoang/laravel-deploy)
- Lightweight deployment scripts
- Focus on simplicity
- Basic server provisioning
- **Best for:** Simple, straightforward deployments

### [SjorsO/deploy-laravel](https://github.com/SjorsO/deploy-laravel)
- Bash-based deployment scripts
- Server setup automation
- Zero-downtime deployments
- **Best for:** Those preferring shell scripts over PHP

### [fadion/Maneuver](https://github.com/fadion/Maneuver)
- Git-based deployment framework
- SSH deployment management
- Multiple server support
- **Best for:** Git-centric deployment workflows

### Why Choose Laravel Deployer?

**Laravel Deployer stands out with:**
- ✅ Built on battle-tested Deployer PHP (used by thousands of projects)
- ✅ Pre-configured tasks specifically for Laravel applications
- ✅ Flexible deployment workflows (quick deploy vs. full deploy with backup)
- ✅ Comprehensive database management (backup, download, restore)
- ✅ Advanced monitoring and health checks
- ✅ Cross-platform desktop notifications
- ✅ Extensive log management capabilities
- ✅ Smart rollback options (quick code-only or full with database restore)
- ✅ Local deployment testing support
- ✅ Secure credential management with gitignored `.deploy/` directory
- ✅ Active maintenance and Laravel version compatibility

## Troubleshooting

### Common Issues

**Permission Errors:**
```bash
# Fix storage permissions on server
vendor/bin/dep deploy:fix-module-permissions staging
```

**SSH Connection Issues:**
```bash
# Test SSH connection
ssh deploy@staging.yourapp.com

# Verify SSH key is added
ssh-add -l
```

**Failed Deployment:**
```bash
# Unlock deployment
vendor/bin/dep deploy:unlock staging

# Check logs
vendor/bin/dep logs:check staging
```

**Database Backup Fails:**
```bash
# Verify database credentials on server
vendor/bin/dep ssh staging
cd /var/www/staging/current
php artisan tinker --execute="dd(config('database.connections.mysql'))"
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT License. See [LICENSE](LICENSE) file for details.

## Author

**Muhammad Shafeeq**
- GitHub: [@hmshafeeq](https://github.com/hmshafeeq)
- Email: hmshafeeq@users.noreply.github.com

## Acknowledgments

- Built on [Deployer](https://deployer.org) by Anton Medvedev
- Inspired by the Laravel deployment ecosystem
- Thanks to all contributors and users

---

**Need Help?** 
- 📖 [Deployer Documentation](https://deployer.org/docs/7.x/)
- 🐛 [Report Issues](https://github.com/hmshafeeq/laravel-deployer/issues)
- 💬 [Discussions](https://github.com/hmshafeeq/laravel-deployer/discussions)
