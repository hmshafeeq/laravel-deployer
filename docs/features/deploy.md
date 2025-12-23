# Deploy Command

Deploy your Laravel application to staging or production with zero downtime.

## Command

```bash
php artisan deploy {environment}
```

## Arguments

- `environment` - The deployment environment (staging, production, etc.)

## Options

- `--no-confirm` - Skip deployment confirmation prompt
- `--skip-health-check` - Skip pre-deployment health checks

## Usage Examples

```bash
# Deploy to staging (interactive)
php artisan deploy staging

# Deploy to production with confirmation
php artisan deploy production

# Deploy to production without confirmation (CI/CD)
php artisan deploy production --no-confirm

# Deploy without health checks
php artisan deploy staging --skip-health-check
```

## What It Does

The deploy command performs a complete zero-downtime deployment:

1. **Health Checks** - Verifies server resources (disk, memory) and endpoints
2. **Lock Deployment** - Prevents concurrent deployments
3. **Create Release** - Generates new timestamped release directory
4. **Build Assets** - Runs `npm run build` locally
5. **Sync Files** - Transfers files to server via rsync
6. **Link Shared** - Creates symlinks to shared directories (storage, .env)
7. **Set Permissions** - Configures writable directories
8. **Install Dependencies** - Runs `composer install --no-dev`
9. **Run Migrations** - Executes database migrations
10. **Link Release** - Atomically switches current symlink to new release
11. **Optimize** - Clears caches, restarts services
12. **Cleanup** - Removes old releases (keeps configured amount)
13. **Unlock** - Releases deployment lock
14. **Notify** - Sends success notification (if configured)

## Prerequisites

- Server configured in `.deploy/deploy.yaml`
- SSH key authentication set up
- Environment credentials in `.deploy/.env.{environment}`
- Server has required permissions and software (PHP, Composer, etc.)

## Configuration

Configure deployment in `.deploy/deploy.yaml`:

```yaml
hosts:
  production:
    hostname: yourapp.com
    remote_user: deploy
    deploy_path: /var/www/production
    branch: main

config:
  keep_releases: 3
  composer_options: '--no-dev --optimize-autoloader'
```

## Error Handling

If deployment fails:
- Deployment lock is automatically released
- Current release remains unchanged (zero-downtime preserved)
- Failure notification is sent (if configured)
- Error details are displayed in console

## Related Commands

- [`deploy:rollback`](rollback.md) - Rollback to previous release
- [`database:backup`](database-backup.md) - Backup database before deployment
- [`deploy:key-generate`](ssh-key-generate.md) - Generate SSH keys for deployment

## Tips

- **Always test on staging first** before deploying to production
- **Backup database** before major updates: `php artisan database:backup production`
- **Use `--no-confirm` in CI/CD** to automate deployments
- **Monitor first deployment** to verify server configuration
- **Set up health checks** for critical applications

## Architecture

This command uses:
- **DeployAction** - Complete 15-step deployment workflow
- **HealthCheckAction** - Pre-deployment verification
- **OptimizeAction** - Post-deployment optimization
- **NotificationAction** - Success/failure notifications

See: `src/Actions/DeployAction.php`
