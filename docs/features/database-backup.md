# Database Backup Command

Create a backup of your remote database.

## Command

```bash
php artisan database:backup {environment?}
```

## Arguments

- `environment` (optional) - The deployment environment (staging, production, etc.)

## Options

- `--select` - Interactively select from configured servers

## Usage Examples

```bash
# Backup production database
php artisan database:backup production

# Backup staging database
php artisan database:backup staging

# Interactive server selection
php artisan database:backup --select

# Prompt for environment
php artisan database:backup
```

## What It Does

The database backup command:

1. **Validates Configuration** - Checks server configuration exists
2. **Connects to Server** - Establishes SSH connection
3. **Reads Database Credentials** - Extracts from remote `.env` file
4. **Creates Backup Directory** - Ensures `{deploy_path}/shared/backups` exists
5. **Generates Backup** - Runs `mysqldump` on remote server
6. **Displays Success** - Shows backup file path and size

## Backup Location

Backups are stored on the **remote server**:

```
/var/www/app/
├── releases/
├── current/
└── shared/
    └── backups/
        ├── backup-2025-01-15-143022.sql
        ├── backup-2025-01-15-180445.sql
        └── backup-2025-01-16-091230.sql
```

## Backup Filename Format

```
backup-YYYY-MM-DD-HHmmss.sql
```

Example: `backup-2025-01-15-143022.sql`

## Prerequisites

- Server configured in `.deploy/deploy.json`
- SSH key authentication set up
- MySQL/MariaDB installed on server
- Database credentials configured in server's `.env` file

## Database Support

Currently supports:
- ✅ MySQL
- ✅ MariaDB

Not yet supported:
- ❌ PostgreSQL
- ❌ SQLite
- ❌ SQL Server

## What Gets Backed Up

The backup includes:
- ✅ All database tables
- ✅ Table structure (schema)
- ✅ All data (rows)
- ✅ Indexes and constraints
- ✅ Views and triggers

## Download Backup

To download the backup to your local machine:

```bash
# Create backup and download in one command
php artisan database:download production
```

See: [database:download](database-download.md)

## Best Practices

### When to Backup

- ✅ **Before deployments** - Especially with migrations
- ✅ **Before major updates** - Database structure changes
- ✅ **Daily/Weekly** - Regular scheduled backups
- ✅ **Before rollbacks** - In case rollback affects data
- ✅ **On-demand** - When needed for testing/development

### Backup Rotation

Configure cleanup in `.deploy/deploy.json`:

```yaml
config:
  keep_backups: 7  # Keep last 7 backups
```

### Large Databases

For large databases (>1GB):
- Backup during low-traffic periods
- Consider compression (gzip)
- Use incremental backups
- Monitor backup duration

## Error Handling

Common errors:

### MySQL Access Denied
```
ERROR 1045 (28000): Access denied for user
```
**Fix**: Check database credentials in server's `.env` file

### Database Not Found
```
ERROR 1049 (42000): Unknown database
```
**Fix**: Verify `DB_DATABASE` in server's `.env` file

### Insufficient Disk Space
```
No space left on device
```
**Fix**: Clean old backups or increase disk space

## Manual Backup

To manually backup on the server:

```bash
# SSH to server
ssh deploy@yourserver.com

# Create backup directory
mkdir -p /var/www/app/shared/backups

# Run mysqldump
mysqldump -u username -p database_name > /var/www/app/shared/backups/manual-backup.sql

# With compression
mysqldump -u username -p database_name | gzip > backup.sql.gz
```

## Related Commands

- [`database:download`](database-download.md) - Download backup to local machine
- [`database:upload`](database-upload.md) - Upload backup to server
- [`database:restore`](database-restore.md) - Restore from backup
- [`deploy`](deploy.md) - Deploy application

## Tips

- **Automate backups** using cron jobs on server
- **Test restores regularly** to verify backups work
- **Store backups off-server** for disaster recovery
- **Encrypt sensitive backups** before storing
- **Document backup procedures** for your team

## Architecture

This command uses:
- **DatabaseAction** - `backup()` method
- **CommandService** - Remote command execution
- **ConfigService** - Load deployment configuration

See: `src/Actions/DatabaseAction.php:22`
