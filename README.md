# Laravel Deployer

A lightweight, zero-downtime deployment package for Laravel applications using Spatie SSH.

## Features

- 🚀 **Zero-Downtime Deployment** - Uses atomic symlink swapping for seamless deployments
- ⏪ **Instant Rollback** - One-command rollback to any previous release
- 📦 **Release Management** - Maintains deployment history with configurable retention
- 🔒 **Deployment Locking** - Prevents concurrent deployments
- 🗄️ **Database Operations** - Backup, download, upload, and restore database with ease
- 🔄 **Service Management** - Auto-detect and restart PHP-FPM, Nginx, and Supervisor
- ❤️ **Health Checks** - Resource monitoring and endpoint health verification
- 🛡️ **Failsafe Mechanisms** - Multiple safety features for production deployments
- 📊 **Verbose Output** - Beautiful, colored output showing deployment progress
- 🧪 **Fully Tested** - Comprehensive test suite with Pest v4

## Requirements

- PHP 8.2 or higher
- Laravel 11.0 or 12.0
- Composer
- SSH access to deployment server
- rsync installed on both local and remote machines

## Installation

Install the package via Composer:

```bash
composer require shaf/laravel-deployer
```

Run the installation command to generate configuration files:

```bash
php artisan laravel-deployer:install
```

This will create:
- `.deploy/deploy.yaml` - Main deployment configuration
- `.deploy/.env.{environment}.example` - Environment-specific credentials
- `.gitignore` entry for `.deploy/` directory

## Configuration

### 1. Set up deployment configuration

Edit `.deploy/deploy.yaml`:

```yaml
staging:
  hostname: your-server.com
  remote_user: deploy
  deploy_path: /var/www/your-app
  repository: git@github.com:username/repo.git
  branch: main
  shared_dirs:
    - storage/app
    - storage/framework
    - storage/logs
  shared_files:
    - .env
  writable_dirs:
    - bootstrap/cache
    - storage
  keep_releases: 3
  rsync:
    - app
    - bootstrap
    - config
    - database
    - public
    - resources
    - routes
    - composer.json
    - composer.lock
    - artisan
  health_checks:
    endpoints:
      - https://your-app.com/api/health

production:
  hostname: production-server.com
  remote_user: deploy
  deploy_path: /var/www/production
  repository: git@github.com:username/repo.git
  branch: production
  # ... same structure as staging
```

### 2. Set up environment credentials

Copy and edit environment files:

```bash
cp .deploy/.env.staging.example .deploy/.env.staging
cp .deploy/.env.production.example .deploy/.env.production
```

Edit `.deploy/.env.staging`:

```env
DEPLOY_HOST=your-server.com
DEPLOY_USER=deploy
DEPLOY_PATH=/var/www/your-app
DEPLOY_BRANCH=main
```

## Usage

### Deployment

Deploy to an environment:

```bash
php artisan deploy staging
```

Deploy without confirmation prompt:

```bash
php artisan deploy staging --no-confirm
```

### Database Operations

#### Backup Database

Create a database backup on the remote server:

```bash
php artisan database:backup staging
```

#### Download Database

Download the latest database backup:

```bash
php artisan database:download staging
```

Download a specific backup:

```bash
php artisan database:download staging --list
php artisan database:download staging 2
```

Choose download method:

```bash
php artisan database:download staging --method=scp
php artisan database:download staging --method=rsync
```

#### Upload Database

Upload a local backup to a remote server:

```bash
php artisan database:upload /path/to/backup.sql.gz --target=user@host --key=~/.ssh/id_rsa
```

#### Restore Database

Restore a database backup locally:

```bash
php artisan database:restore /path/to/backup.sql.gz
```

### Cache Management

Clear all caches and restart services:

```bash
php artisan deployer:clear staging
```

### Rollback

Rollback to the previous release:

```bash
php artisan deploy:rollback staging
```

Rollback to a specific release:

```bash
# List available releases first
php artisan deploy:rollback staging --release=202501.2
```

Skip confirmation prompt:

```bash
php artisan deploy:rollback staging --no-confirm
```

**Important Notes**:
- Rollback changes the application code instantly
- Database migrations are **NOT** automatically rolled back
- You must manually rollback database changes if needed:
  ```bash
  ssh user@server
  cd /var/www/your-app/current
  php artisan migrate:rollback --step=N
  ```
- Rollback clears all caches and restarts services
- At least 2 releases must exist to rollback

## Deployment Workflow

The deployment process follows these steps:

1. **Validation** - Confirms deployment target
2. **Lock Check** - Ensures no concurrent deployment
3. **Lock** - Creates deployment lock
4. **Release** - Creates new release directory
5. **Build Assets** - Compiles frontend assets (npm run build)
6. **Rsync** - Syncs files to server
7. **Shared** - Links shared directories and files
8. **Writable** - Sets proper permissions
9. **Vendors** - Installs composer dependencies
10. **Storage Link** - Creates storage symlink
11. **Cache** - Builds configuration, view, and route caches
12. **Optimize** - Runs Laravel optimization
13. **Migrate** - Runs database migrations
14. **Services** - Restarts PHP-FPM, Nginx, Supervisor
15. **Symlink** - Atomic swap to new release
16. **Cleanup** - Removes old releases
17. **Health Check** - Verifies deployment success
18. **Unlock** - Removes deployment lock
19. **Notification** - Sends desktop notification

## Directory Structure

The deployment creates the following structure on the remote server:

```
/var/www/your-app/
├── .dep/                    # Deployment metadata
│   ├── deploy.lock         # Deployment lock file
│   └── release_counter/    # Release numbering
├── releases/               # All releases
│   ├── 202501.1/          # Release YYYYMM.N
│   ├── 202501.2/
│   └── 202501.3/
├── shared/                 # Shared files/directories
│   ├── .env
│   └── storage/
├── current -> releases/202501.3/  # Symlink to active release
└── backups/               # Database backups
```

## Configuration Options

### Deploy Configuration

| Option | Description | Example |
|--------|-------------|---------|
| `hostname` | Remote server hostname | `server.com` |
| `remote_user` | SSH user | `deploy` |
| `deploy_path` | Deployment directory | `/var/www/app` |
| `repository` | Git repository | `git@github.com:user/repo.git` |
| `branch` | Git branch | `main` |
| `shared_dirs` | Directories to share between releases | `['storage']` |
| `shared_files` | Files to share between releases | `['.env']` |
| `writable_dirs` | Directories needing write permission | `['storage']` |
| `keep_releases` | Number of releases to keep | `3` |
| `rsync` | Files/directories to sync | `['app', 'public']` |
| `health_checks.endpoints` | URLs to check after deployment | `['https://app.com/health']` |

### Environment Variables

| Variable | Description |
|----------|-------------|
| `DEPLOY_HOST` | Override hostname from deploy.yaml |
| `DEPLOY_USER` | Override remote user |
| `DEPLOY_PATH` | Override deployment path |
| `DEPLOY_BRANCH` | Override git branch |

## Failsafe Mechanisms

Laravel Deployer includes multiple failsafe mechanisms to ensure safe deployments:

### Built-in Safety Features

1. **Zero-Downtime Deployment** - Atomic symlink swapping ensures no service interruption
2. **Deployment Locking** - Prevents concurrent deployments that could corrupt the system
3. **Release History** - Maintains multiple releases for quick rollback
4. **Instant Rollback** - One-command rollback to any previous release
5. **Pre-Deployment Validation** - Confirms target before deployment
6. **Health Checks** - Verifies system resources and endpoint availability
7. **Shared Resources** - Data persistence across deployments
8. **Graceful Error Handling** - Automatic cleanup on failures
9. **Service Management** - Safe service restarts with fallbacks
10. **Desktop Notifications** - Real-time deployment status alerts

### Rollback Procedure

If a deployment introduces issues:

```bash
# Quick rollback to previous release
php artisan deploy:rollback staging

# Or rollback to specific release
php artisan deploy:rollback staging --release=202501.2
```

The rollback process:
- Changes code instantly (atomic symlink swap)
- Clears all caches
- Restarts queue workers
- Restarts services (PHP-FPM, Nginx)
- **Does NOT rollback database migrations** (manual intervention required)

### Additional Recommendations

For enhanced safety, consider implementing:

- **Automatic Rollback on Failure** - Auto-rollback if health checks fail
- **Database Backup Before Migration** - Safety net for schema changes
- **Smoke Tests** - Automated testing after deployment
- **Deployment Windows** - Restrict deployments to low-traffic periods
- **Slack/Email Notifications** - Team awareness of deployments
- **Maintenance Mode** - User-friendly messages during deployment

See [FAILSAFE_MECHANISMS.md](FAILSAFE_MECHANISMS.md) for detailed recommendations and implementation guides.

## Testing

Run the test suite:

```bash
composer test
```

Or using Pest directly:

```bash
vendor/bin/pest
```

Run specific tests:

```bash
vendor/bin/pest tests/Unit/DeployerTest.php
vendor/bin/pest --filter="deployment tasks"
```

## Troubleshooting

### Deployment Locked

If deployment fails and leaves a lock:

```bash
# SSH into server
ssh user@server

# Remove lock file
rm /var/www/your-app/.dep/deploy.lock
```

### Permission Errors

Ensure the deployment user has proper permissions:

```bash
# On the server
sudo chown -R deploy:deploy /var/www/your-app
sudo chmod -R 755 /var/www/your-app
```

### PHP-FPM Not Restarting

Grant sudo permissions for service restart:

```bash
# Edit sudoers file
sudo visudo

# Add this line
deploy ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart php*-fpm, /usr/bin/systemctl restart nginx, /usr/sbin/supervisorctl reload
```

## Architecture

This package replaces the traditional `deployer/deployer` dependency with a lightweight implementation using:

- **Spatie SSH** - For remote command execution
- **Symfony Process** - For local command execution
- **Symfony YAML** - For configuration parsing

The architecture follows a task-based approach:

- `Deployer` - Core class for SSH and command execution
- `DeploymentTasks` - All deployment-related tasks
- `DatabaseTasks` - Database backup/restore operations
- `ServiceTasks` - PHP-FPM, Nginx, Supervisor management
- `HealthCheckTasks` - Resource and endpoint monitoring
- `NotificationTasks` - Desktop notifications

## Security

- All `.deploy/` files are gitignored by default
- SSH keys are never committed to version control
- Database credentials stored in environment-specific `.env` files
- Deployment locks prevent concurrent access
- Health checks verify successful deployment

## Contributing

Contributions are welcome! Please ensure:

1. All tests pass: `composer test`
2. Code follows PSR-12 standards
3. New features include tests
4. Update README for new features

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).

## Credits

- Built by [Muhammad Shafeeq](https://github.com/hmshafeeq)
- Uses [Spatie SSH](https://github.com/spatie/ssh)
- Inspired by [Deployer](https://deployer.org)

## Support

- [GitHub Issues](https://github.com/hmshafeeq/laravel-deployer/issues)
- [GitHub Discussions](https://github.com/hmshafeeq/laravel-deployer/discussions)
