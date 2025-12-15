# Clear Command

Clear deployment locks and clean up deployment artifacts.

## Command

```bash
php artisan deploy:clear {environment}
```

## Arguments

- `environment` - The deployment environment (staging, production, etc.)

## Options

- `--force` - Force clear without confirmation

## Usage Examples

```bash
# Clear deployment lock on production
php artisan deploy:clear production

# Clear without confirmation
php artisan deploy:clear staging --force
```

## What It Does

The clear command performs cleanup operations:

1. **Validates Configuration** - Checks environment exists in deploy.yaml
2. **Connects to Server** - Establishes SSH connection
3. **Displays Current State** - Shows lock status
4. **Confirms Action** - Prompts for confirmation (unless `--force`)
5. **Removes Lock File** - Deletes deployment lock
6. **Displays Success** - Confirms cleanup completed

## Deployment Locks

### What is a Lock?

When deploying, Laravel Deployer creates a lock file to prevent concurrent deployments:

```
/var/www/app/
├── releases/
├── current/
└── .dep/
    └── deploy.lock  ← Lock file
```

### Why Locks Matter

Locks prevent:
- ❌ Multiple deployments running simultaneously
- ❌ Race conditions during deployment
- ❌ Corrupted releases
- ❌ Symlink switching conflicts

### When Locks Get Stuck

Locks can remain if:
- 💥 Deployment process crashes
- 💥 Network connection drops
- 💥 SSH session terminates unexpectedly
- 💥 Process is force-killed

## When to Use

Use `deploy:clear` when:

### Stuck Deployment
```
$ php artisan deploy production

❌ Deployment is locked. Another deployment may be in progress.

# Clear the lock
$ php artisan deploy:clear production
```

### Failed Deployment
```
# Deployment failed but lock remains
$ php artisan deploy:clear production --force
$ php artisan deploy production
```

### Abandoned Deployment
```
# Someone started deployment and disconnected
$ php artisan deploy:clear production
```

### Testing/Development
```
# Quick clear during testing
$ php artisan deploy:clear staging --force
```

## Lock Information

The command shows lock details before clearing:

```bash
$ php artisan deploy:clear production

🔒 Deployment Lock Status

Environment: production
Server: deploy@yourapp.com
Lock file: /var/www/production/.dep/deploy.lock

Lock exists: Yes
Created: 2025-01-16 14:30:15
Age: 2 hours ago

⚠️  Clearing this lock will allow new deployments to proceed.

Are you sure you want to clear the lock? (yes/no) [no]:
```

## Safety Checks

### Confirmation Required

By default, the command requires confirmation:

```bash
$ php artisan deploy:clear production

Are you sure you want to clear the lock? (yes/no) [no]: yes
```

### Force Clear

Skip confirmation with `--force`:

```bash
$ php artisan deploy:clear production --force
```

**⚠️ Use with caution!** Only force clear when certain no deployment is running.

## Checking Lock Status

To check if deployment is locked without clearing:

```bash
# SSH to server
ssh deploy@yourserver.com

# Check lock file
ls -la /var/www/app/.dep/deploy.lock

# View lock details
cat /var/www/app/.dep/deploy.lock
```

## Manual Lock Removal

If the command fails, manually remove lock:

```bash
# SSH to server
ssh deploy@yourserver.com

# Remove lock file
rm /var/www/app/.dep/deploy.lock

# Verify removal
ls -la /var/www/app/.dep/
```

## What NOT to Clear

This command only clears deployment locks. It does **NOT**:

- ❌ Remove releases
- ❌ Delete backups
- ❌ Clear application cache
- ❌ Reset database
- ❌ Remove uploaded files

For those operations, use appropriate commands or SSH directly.

## Preventing Lock Issues

### Best Practices

1. **Stable Connection** - Use stable network for deployments
2. **Don't Force-Kill** - Let deployments complete or fail gracefully
3. **Monitor Progress** - Watch deployment output
4. **Use Timeouts** - Configure reasonable timeout values
5. **Document Procedures** - Train team on proper deployment

### Automation

In CI/CD, consider clearing locks before deployment:

```bash
# CI/CD script
php artisan deploy:clear production --force
php artisan deploy production --no-confirm
```

**⚠️ Risk**: Could clear lock from legitimate deployment!

Better approach:

```bash
# Only clear if lock is old (e.g., >30 minutes)
ssh deploy@server '
    LOCK=/var/www/app/.dep/deploy.lock
    if [ -f "$LOCK" ]; then
        AGE=$(($(date +%s) - $(stat -c %Y "$LOCK")))
        if [ $AGE -gt 1800 ]; then
            rm "$LOCK"
            echo "Cleared stale lock (${AGE}s old)"
        fi
    fi
'
```

## Error Handling

Common errors:

### Lock File Not Found
```
Lock file does not exist
```
**Fix**: No action needed, already unlocked

### SSH Connection Failed
```
Could not connect to server
```
**Fix**: Verify SSH connectivity: `ssh deploy@server.com`

### Permission Denied
```
Permission denied: deploy.lock
```
**Fix**: Check file ownership and permissions on server

## Troubleshooting Workflow

```bash
# 1. Check if deployment is actually running
ssh deploy@server.com "ps aux | grep deploy"

# 2. If no process found, safe to clear
php artisan deploy:clear production --force

# 3. Try deployment again
php artisan deploy production

# 4. If still fails, check server logs
ssh deploy@server.com "tail -f /var/log/deployment.log"
```

## Lock File Format

The lock file typically contains:

```json
{
  "locked_at": "2025-01-16T14:30:15Z",
  "locked_by": "user@hostname",
  "process_id": 12345
}
```

Or simply:
```
1705414215
```
(Unix timestamp)

## Related Commands

- [`deploy`](deploy.md) - Deploy application (creates lock)
- [`deploy:rollback`](rollback.md) - Rollback deployment (uses lock)

## Tips

- **Check before clearing** - Ensure no deployment is running
- **Communicate with team** - Coordinate before clearing locks
- **Use --force cautiously** - Only when certain it's safe
- **Monitor deployments** - Watch for completion
- **Automate wisely** - Don't blindly clear locks in CI/CD

## Architecture

This command:
- Connects to remote server via SSH
- Checks existence of `.dep/deploy.lock`
- Removes lock file if confirmed
- Uses CommandService for remote execution

See: `src/Commands/ClearCommand.php`
