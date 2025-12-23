# Laravel Deployer - Command Reference

Complete reference guide for all Laravel Deployer commands.

## 📚 Table of Contents

### Setup & Configuration
- [**install**](install.md) - Initialize Laravel Deployer in your project
- [**deploy:key-generate**](ssh-key-generate.md) - Interactive SSH key generator

### Deployment Operations
- [**deploy**](deploy.md) - Deploy application with zero downtime
- [**deploy:rollback**](rollback.md) - Rollback to previous release
- [**deploy:clear**](clear.md) - Clear deployment locks

### Database Operations
- [**database:backup**](database-backup.md) - Backup remote database
- [**database:download**](database-download.md) - Download backup to local machine
- [**database:upload**](database-upload.md) - Upload backup to server
- [**database:restore**](database-restore.md) - Restore local database from backup

---

## Command Categories

### 🚀 Deployment Commands

| Command | Description | Typical Use |
|---------|-------------|-------------|
| [`deploy`](deploy.md) | Deploy application | Daily deployments |
| [`deploy:rollback`](rollback.md) | Rollback deployment | Emergency fixes |
| [`deploy:clear`](clear.md) | Clear stuck locks | Troubleshooting |

### 💾 Database Commands

| Command | Description | Typical Use |
|---------|-------------|-------------|
| [`database:backup`](database-backup.md) | Create remote backup | Pre-deployment safety |
| [`database:download`](database-download.md) | Download backup | Local development |
| [`database:upload`](database-upload.md) | Upload backup | Migration/restore |
| [`database:restore`](database-restore.md) | Restore local DB | Testing with real data |

### 🔧 Setup & Utility Commands

| Command | Description | Typical Use |
|---------|-------------|-------------|
| [`laravel-deployer:install`](install.md) | Initialize package | First-time setup |
| [`deploy:key-generate`](ssh-key-generate.md) | Generate SSH keys | Authentication setup |

---

## Quick Start

### First Time Setup

```bash
# 1. Install and configure
composer require shaf/laravel-deployer
php artisan laravel-deployer:install

# 2. Generate SSH keys
php artisan deploy:key-generate deploy@yourapp.com

# 3. Configure deployment
nano .deploy/deploy.yaml

# 4. Deploy!
php artisan deploy staging
```

### Daily Usage

```bash
# Deploy to staging
php artisan deploy staging

# Deploy to production (with confirmation)
php artisan deploy production

# Rollback if issues
php artisan deploy:rollback production
```

### Database Management

```bash
# Backup before deployment
php artisan database:backup production

# Download for local testing
php artisan database:download production
php artisan database:restore --latest

# Upload backup to server
php artisan database:upload backup.sql --target=deploy@server.com
```

---

## Command Patterns

### Interactive vs Non-Interactive

Most commands support both modes:

```bash
# Interactive (prompts for input)
php artisan deploy

# Non-interactive (no prompts)
php artisan deploy production --no-confirm
```

### Environment Selection

Commands that operate on servers require environment:

```bash
php artisan deploy {environment}
php artisan database:backup {environment}
php artisan deploy:rollback {environment}
```

### Force Operations

Skip confirmations with force flags:

```bash
php artisan deploy:rollback production --no-confirm
php artisan deploy:key-generate email@app.com --force
php artisan deploy:clear production --force
```

---

## Command Options Reference

### Common Options

| Option | Commands | Description |
|--------|----------|-------------|
| `--no-confirm` | deploy, rollback | Skip confirmation prompts |
| `--force` | clear, key-generate | Force operation without prompting |
| `--select` | database:backup, database:download | Interactive server selection |

### Deployment Options

| Option | Command | Description |
|--------|---------|-------------|
| `--skip-health-check` | deploy | Skip pre-deployment health checks |
| `--no-migrate` | database:restore | Skip migrations after restore |

### SSH Key Options

| Option | Command | Description |
|--------|---------|-------------|
| `--name` | deploy:key-generate | Custom key name |
| `--key` | database:upload | Custom SSH key path |
| `--port` | database:upload | Custom SSH port |

---

## Workflow Examples

### Standard Deployment Workflow

```bash
# 1. Backup database
php artisan database:backup production

# 2. Deploy application
php artisan deploy production

# 3. If issues, rollback
php artisan deploy:rollback production

# 4. If database issues, restore
php artisan database:restore --latest
```

### Local Development Workflow

```bash
# 1. Download fresh data from staging
php artisan database:download staging

# 2. Restore to local database
php artisan database:restore --latest

# 3. Run local development server
php artisan serve
```

### Emergency Rollback Workflow

```bash
# 1. Immediate rollback
php artisan deploy:rollback production --no-confirm

# 2. Verify services
ssh deploy@production.com "sudo systemctl status php8.2-fpm nginx"

# 3. Check application
curl https://yourapp.com/health
```

### CI/CD Deployment Workflow

```bash
#!/bin/bash
# .github/workflows/deploy.yml or similar

# Clear any stuck locks
php artisan deploy:clear production --force

# Deploy without confirmation
php artisan deploy production --no-confirm

# If deployment fails, rollback
if [ $? -ne 0 ]; then
    php artisan deploy:rollback production --no-confirm
    exit 1
fi
```

---

## Architecture Overview

All commands use the simplified action-based architecture:

### Actions (Complete Workflows)
- **DeployAction** - Full deployment process (15 steps)
- **RollbackAction** - Complete rollback workflow
- **DatabaseAction** - All database operations
- **HealthCheckAction** - Pre-deployment verification
- **OptimizeAction** - Cache & service optimization
- **NotificationAction** - Deployment notifications

### Services (Core Functionality)
- **CommandService** - Execute local/remote commands
- **DeploymentService** - Release & lock management
- **ConfigService** - Configuration loading
- **RsyncService** - File synchronization

### Command → Action Mapping

```php
DeployCommand → DeployAction + OptimizeAction + NotificationAction
RollbackCommand → RollbackAction + OptimizeAction + NotificationAction
DatabaseBackupCommand → DatabaseAction::backup()
DatabaseDownloadCommand → DatabaseAction::backupAndDownload()
DatabaseUploadCommand → DatabaseAction::upload()
```

---

## Getting Help

### Command Help

View help for any command:

```bash
php artisan help deploy
php artisan help database:backup
php artisan help deploy:key-generate
```

### Documentation

- **This Guide** - Command reference and examples
- **[Main README](../../README.md)** - Package overview and installation
- **[Architecture Docs](../architecture/)** - Technical architecture details

### Support

- **GitHub Issues** - Report bugs or request features
- **Discussion** - Ask questions and share tips

---

## Tips & Best Practices

### Deployment
- ✅ Always test on staging before production
- ✅ Backup database before major updates
- ✅ Use `--no-confirm` in CI/CD pipelines
- ✅ Monitor first deployment of new features
- ✅ Set up health check endpoints

### Database
- ✅ Backup regularly (daily/weekly)
- ✅ Test restores periodically
- ✅ Store backups off-server
- ✅ Encrypt sensitive backups
- ✅ Clean up old backups

### Security
- ✅ Use SSH key authentication
- ✅ Never commit credentials
- ✅ Use separate keys per environment
- ✅ Rotate SSH keys regularly
- ✅ Audit server access

### Team Collaboration
- ✅ Document deployment procedures
- ✅ Coordinate major deployments
- ✅ Communicate rollbacks
- ✅ Share configuration (deploy.yaml)
- ✅ Keep credentials private

---

## Troubleshooting

### Common Issues

| Issue | Solution |
|-------|----------|
| SSH connection failed | Check [`deploy:key-generate`](ssh-key-generate.md) |
| Deployment locked | Use [`deploy:clear`](clear.md) |
| Health check failed | Use `--skip-health-check` or fix issues |
| Rollback no previous release | Need at least 2 releases on server |
| Database restore failed | Check local database configuration |

### Getting More Help

1. **Check command documentation** - Click links above
2. **View command help** - `php artisan help {command}`
3. **Check error messages** - Often contain solutions
4. **Review server logs** - SSH to server and check logs
5. **Ask for help** - GitHub issues or discussions

---

**Happy Deploying!** 🚀

For the complete package documentation, see the [main README](../../README.md).
