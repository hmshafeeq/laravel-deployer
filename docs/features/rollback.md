# Rollback Command

Instantly rollback your Laravel application to the previous release.

## Command

```bash
php artisan deploy:rollback {environment}
```

## Arguments

- `environment` - The deployment environment (staging, production, etc.)

## Options

- `--no-confirm` - Skip rollback confirmation prompt

## Usage Examples

```bash
# Rollback production (interactive)
php artisan deploy:rollback production

# Rollback staging without confirmation
php artisan deploy:rollback staging --no-confirm

# Quick rollback in emergency
php artisan deploy:rollback production --no-confirm
```

## What It Does

The rollback command performs an instant rollback to the previous release:

1. **Lock Deployment** - Prevents concurrent operations
2. **Validate Previous Release** - Ensures a previous release exists
3. **Display Releases** - Shows current and previous release information
4. **Confirm Rollback** - Prompts for confirmation (unless `--no-confirm`)
5. **Symlink Previous** - Atomically switches current symlink to previous release
6. **Optimize** - Clears caches and restarts services
7. **Unlock** - Releases deployment lock
8. **Notify** - Sends rollback notification (if configured)

## How It Works

The rollback is **instant** because Laravel Deployer uses release directories:

```
/var/www/app/
├── releases/
│   ├── 202501.1/  ← Previous release
│   ├── 202501.2/  ← Current release (broken)
│   └── 202501.3/  ← Latest release
├── current → releases/202501.3  ← Symlink
└── shared/
```

Rollback simply changes the `current` symlink:
```bash
# Before rollback
current → releases/202501.3

# After rollback
current → releases/202501.2
```

## Prerequisites

- At least 2 releases must exist on the server
- Server configured in `.deploy/deploy.json`
- SSH key authentication set up

## When to Use

Use rollback when:
- ✅ New deployment introduces bugs
- ✅ Application errors after deployment
- ✅ Database migration issues
- ✅ Configuration problems
- ✅ Need to quickly restore service

## Important Notes

### What Rollback Does NOT Do

- ❌ **Does not rollback database migrations** - Handle separately
- ❌ **Does not restore database data** - Use database restore
- ❌ **Does not restore deleted files** - Old release must still exist
- ❌ **Does not rollback environment variables** - `.env` is in shared directory

### Database Considerations

If you ran migrations during deployment:
1. Rollback application first: `php artisan deploy:rollback production`
2. Restore database if needed: `php artisan database:restore --latest`

## Error Handling

If rollback fails:
- Deployment lock is automatically released
- Current release remains unchanged
- Error details are displayed in console

Common errors:
- **No previous release** - Only one release exists on server
- **Deployment locked** - Another deployment/rollback is in progress
- **SSH connection failed** - Check server connectivity

## Manual Rollback

If command fails, you can manually rollback:

```bash
# SSH to server
ssh deploy@yourserver.com

# List releases (newest first)
ls -t /var/www/app/releases/

# Symlink to previous release
ln -nfs /var/www/app/releases/202501.2 /var/www/app/current

# Clear cache
cd /var/www/app/current
php artisan optimize:clear

# Restart services
sudo systemctl restart php8.2-fpm
sudo systemctl reload nginx
```

## Related Commands

- [`deploy`](deploy.md) - Deploy application
- [`database:restore`](database-restore.md) - Restore database backup
- [`database:backup`](database-backup.md) - Create database backup

## Tips

- **Backup database before deployments** to enable full rollback
- **Test rollback process** on staging to verify it works
- **Keep multiple releases** by configuring `keep_releases` in deploy.json
- **Document rollback procedures** for your team
- **Monitor after rollback** to ensure stability

## Architecture

This command uses:
- **RollbackAction** - Complete rollback workflow
- **OptimizeAction** - Post-rollback optimization
- **NotificationAction** - Rollback notifications

See: `src/Actions/RollbackAction.php`
