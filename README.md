# Laravel Deployer

A Laravel package that provides deployment automation using Deployer PHP with pre-configured tasks and recipes optimized for Laravel applications.

> **Built on top of [Deployer](https://github.com/deployphp/deployer)** - A deployment tool written in PHP with support for popular frameworks out of the box. Major credits to the Deployer team for creating such an excellent deployment foundation.

## Features

- 🚀 **Quick Setup**: Get deployment ready in minutes with `php artisan laravel-deployer:install`
- 📦 **Pre-configured Tasks**: Database backups, health checks, resource monitoring, and more
- 🔔 **Smart Notifications**: Desktop notifications for deployment success/failure
- 📊 **Health Monitoring**: Comprehensive endpoint testing and server resource checks
- 💾 **Database Management**: Automated backups with download capabilities
- 📝 **Log Analysis**: Automated log checking for errors and warnings
- ⚡ **Quick Rollbacks**: Fast rollback without database changes

## Installation

1. Add the package to your Laravel project:

```bash
composer require --dev shaf/laravel-deployer
```

2. Install deployer/deployer if not already installed:

```bash
composer require --dev deployer/deployer
```

3. Generate the deployment configuration:

```bash
php artisan laravel-deployer:install
```

4. Configure your server details in the generated `deploy.yaml` file.

## Usage

### Basic Deployment

```bash
# Quick deployment (without database backup)
dep deploy:quick staging

# Full deployment (with database backup)
dep deploy staging
```

### Available Tasks

- `deploy` - Full deployment with database backup
- `deploy:quick` - Quick deployment without database backup
- `database:backup` - Create database backup
- `database:download` - Download database backup to local machine
- `deploy:health-check` - Run health checks and endpoint tests
- `health:check-resources` - Check server resources (disk, memory)
- `logs:check` - Analyze application logs for issues
- `logs:list` - List available log files
- `logs:view` - View a log file
- `logs:search` - Search a log file
- `logs:download` - Download a log file
- `rollback:quick` - Quick rollback without database restore
- `rollback:full` - Full rollback including database restore

### Configuration

The `deploy.yaml` file contains all deployment configuration:

```yaml
import:
  - vendor/shaf/laravel-deployer/recipe/deploy.php

config:
  application: 'Your Application Name'
  keep_releases: 3
  default_selector: staging

hosts:
  staging:
    hostname: 'your-staging-server.com'
    remote_user: ubuntu
    deploy_path: '/var/www/your-app-staging'
    branch: dev

  production:
    hostname: 'your-production-server.com'
    remote_user: ubuntu
    deploy_path: '/var/www/your-app-production'
    branch: master
```

## Task Details

### Database Tasks
- **Backup**: Creates compressed SQL backups with automatic cleanup (keeps last 3)
- **Download**: Interactive backup selection with progress indicators

### Health Checks
- **Endpoint Testing**: Tests critical endpoints (/, /admin/login, /user/login, /health)
- **Resource Monitoring**: Checks disk space and memory usage with warnings
- **Application Health**: Custom health endpoint validation with retry logic

### Log Analysis
- **Error Detection**: Scans last 7 days for errors and warnings
- **Issue Reporting**: Shows recent critical issues with context

### Log Management
- **List**: `logs:list` - Lists all available log files on the remote server.
- **View**: `logs:view` - View a specific log file in real-time. Supports following the log.
- **Search**: `logs:search` - Search for a specific term in a log file.
- **Download**: `logs:download` - Download a log file to your local machine.

### Notifications
- **Desktop Alerts**: Cross-platform notifications (macOS, Linux, Windows)
- **Deployment Status**: Success/failure notifications with details

## Requirements

- PHP ^8.2
- Laravel ^11.0|^12.0
- Deployer ^7.0

## License

MIT License. See LICENSE file for details.

## Author

**Muhammad Shafeeq**
- GitHub: [@hmshafeeq](https://github.com/hmshafeeq)eq)