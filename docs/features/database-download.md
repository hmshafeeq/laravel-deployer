# Database Download Command

Download a database backup from your remote server to your local machine.

## Command

```bash
php artisan database:download {environment?}
```

## Arguments

- `environment` (optional) - The deployment environment (staging, production, etc.)

## Options

- `--select` - Interactively select from configured servers

## Usage Examples

```bash
# Download from production
php artisan database:download production

# Download from staging
php artisan database:download staging

# Interactive server selection
php artisan database:download --select
```

## What It Does

The database download command:

1. **Creates Backup** - Runs `mysqldump` on remote server
2. **Prepares Local Directory** - Creates `.deploy/downloads/backups/`
3. **Downloads via SCP** - Transfers backup file to local machine
4. **Displays Success** - Shows local file path and size

## Download Location

Backups are downloaded to your **local machine**:

```
your-laravel-project/
└── .deploy/
    └── downloads/
        └── backups/
            ├── backup-2025-01-15-143022.sql
            ├── backup-2025-01-15-180445.sql
            └── backup-2025-01-16-091230.sql
```

## Workflow

```
1. Remote Server
   └── Run mysqldump → backup-2025-01-16-091230.sql

2. Transfer (SCP)
   └── Download backup file

3. Local Machine
   └── Save to .deploy/downloads/backups/
```

## Prerequisites

- Server configured in `.deploy/deploy.yaml`
- SSH key authentication set up
- MySQL/MariaDB on server
- Sufficient local disk space
- `scp` command available locally

## Use Cases

### Development/Testing
```bash
# Download production data for local testing
php artisan database:download production

# Restore to local database
php artisan database:restore --latest
```

### Backup Storage
```bash
# Download for off-site backup
php artisan database:download production

# Archive backups
tar -czf backups-$(date +%Y%m%d).tar.gz .deploy/downloads/backups/
```

### Database Migration
```bash
# Download from old server
php artisan database:download production

# Upload to new server
php artisan database:upload backup-file.sql --target=deploy@newserver.com
```

## File Size Considerations

### Small Databases (<100MB)
- Download immediately
- No special handling needed

### Medium Databases (100MB-1GB)
- May take several minutes
- Ensure stable internet connection
- Monitor download progress

### Large Databases (>1GB)
- Consider compression (gzip)
- Download during off-peak hours
- Use rsync with resume capability
- Consider incremental backups

## Compression

To compress before downloading:

```bash
# Manual compression on server
ssh deploy@yourserver.com
mysqldump -u user -p database | gzip > backup.sql.gz
exit

# Download compressed file
scp deploy@yourserver.com:/path/to/backup.sql.gz .deploy/downloads/backups/
```

## Error Handling

Common errors:

### SCP Connection Failed
```
Permission denied (publickey)
```
**Fix**: Verify SSH key authentication: `ssh deploy@server.com`

### Local Directory Not Writable
```
Failed to create directory
```
**Fix**: Check local permissions: `chmod 755 .deploy/downloads/`

### Insufficient Disk Space
```
No space left on device
```
**Fix**: Free up local disk space or change download location

### Network Timeout
```
Connection timed out
```
**Fix**: Check internet connection, try again, or use rsync with resume

## Manual Download

To manually download a backup:

```bash
# List available backups on server
ssh deploy@yourserver.com "ls -lh /var/www/app/shared/backups/"

# Download specific backup
scp deploy@yourserver.com:/var/www/app/shared/backups/backup-2025-01-16.sql .

# Download with compression
ssh deploy@yourserver.com "gzip -c /var/www/app/shared/backups/backup.sql" | gunzip > local-backup.sql
```

## Cleanup

Downloaded backups are stored locally forever. Clean up periodically:

```bash
# View local backups
ls -lh .deploy/downloads/backups/

# Remove old backups
rm .deploy/downloads/backups/backup-2025-01-*.sql

# Keep only last 5 backups
cd .deploy/downloads/backups
ls -t | tail -n +6 | xargs rm
```

## Related Commands

- [`database:backup`](database-backup.md) - Create backup on server (without download)
- [`database:restore`](database-restore.md) - Restore downloaded backup to local database
- [`database:upload`](database-upload.md) - Upload backup to server

## Tips

- **Verify download integrity** by checking file sizes
- **Test downloads regularly** to ensure backups are accessible
- **Store securely** - backups may contain sensitive data
- **Encrypt backups** if storing on shared systems
- **Document procedures** for your team

## Architecture

This command uses:
- **DatabaseAction** - `backupAndDownload()` method
- **CommandService** - Remote mysqldump execution
- **ConfigService** - Load deployment configuration

See: `src/Actions/DatabaseAction.php:110`
