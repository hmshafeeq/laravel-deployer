# Database Restore Command

Restore your local database from a downloaded backup file.

## Command

```bash
php artisan database:restore {backup?}
```

## Arguments

- `backup` (optional) - Backup file name or number to restore

## Options

- `--list` - List available backups without restoring
- `--latest` - Restore the latest backup without prompting
- `--no-migrate` - Skip running migrations after restore

## Usage Examples

```bash
# Interactive selection
php artisan database:restore

# Restore latest backup
php artisan database:restore --latest

# Restore specific backup by name
php artisan database:restore backup-2025-01-15-143022.sql

# Restore by number (from list)
php artisan database:restore 1

# List available backups
php artisan database:restore --list

# Restore without migrations
php artisan database:restore --latest --no-migrate
```

## What It Does

The database restore command:

1. **Scans Backups Directory** - Lists available backup files
2. **Interactive Selection** - Prompts to choose backup (unless specified)
3. **Displays Backup Info** - Shows filename, size, and date
4. **Confirms Restore** - Warns about data replacement
5. **Tests Connection** - Verifies database is accessible
6. **Restores Database** - Runs MySQL restore from backup
7. **Runs Migrations** - Updates schema (unless skipped)
8. **Offers Password Reset** - For non-production environments

## Backup Location

Restores from backups in `.deploy/downloads/backups/`:

```
your-laravel-project/
└── .deploy/
    └── downloads/
        └── backups/
            ├── backup-2025-01-15-143022.sql  ← Available
            ├── backup-2025-01-15-180445.sql  ← Available
            └── backup-2025-01-16-091230.sql  ← Available
```

## Interactive Workflow

```bash
$ php artisan database:restore

📋 Available database backups:

   1. backup-2025-01-16-091230.sql (45.2MB) - 2025-01-16 09:12:30
   2. backup-2025-01-15-180445.sql (44.8MB) - 2025-01-15 18:04:45
   3. backup-2025-01-15-143022.sql (44.5MB) - 2025-01-15 14:30:22

Enter backup number to restore (1-3) or press Enter for latest [1]: 1

📋 Selected backup: backup-2025-01-16-091230.sql

🗄️  Database configuration:
   Host: 127.0.0.1:3306
   Database: myapp_local
   Username: root

⚠️  This will COMPLETELY REPLACE all data in database 'myapp_local'!
Are you sure you want to continue? (yes/no) [no]:
```

## Prerequisites

- Downloaded backups in `.deploy/downloads/backups/`
- MySQL/MariaDB installed locally
- Database configured in `.env` file
- Database user has write permissions

## Database Support

Currently supports:
- ✅ MySQL
- ✅ MariaDB

Not yet supported:
- ❌ PostgreSQL
- ❌ SQLite
- ❌ SQL Server

## What Gets Restored

The restore includes:
- ✅ All database tables (existing tables are dropped)
- ✅ Table structure (schema)
- ✅ All data (rows)
- ✅ Indexes and constraints
- ✅ Views and triggers

## ⚠️ Important Warnings

### Data Loss
- **ALL existing data will be DELETED**
- Tables not in backup will be removed
- This operation **CANNOT be undone**
- Always backup current data first

### Before Restoring

```bash
# 1. Backup current local database
mysqldump -u root -p myapp_local > current-backup.sql

# 2. Then restore
php artisan database:restore --latest
```

## Migration Handling

### Auto-Migration (Default)
By default, migrations run after restore to update schema:

```bash
php artisan database:restore --latest
# After restore, runs: php artisan migrate --force
```

### Skip Migrations
```bash
php artisan database:restore --latest --no-migrate
```

### Manual Migration
```bash
# Restore without migrations
php artisan database:restore --latest --no-migrate

# Run migrations manually
php artisan migrate --force
```

## Password Reset (Development)

In non-production environments, the command offers to reset a user password:

```bash
Would you like to reset a user password for testing? (yes/no) [yes]:

Enter user email [admin@example.com]: admin@test.com

Enter new password [admin@123]: mypassword

✅ Password for user 'admin@test.com' has been reset successfully.
   New password: mypassword
```

This feature is **disabled in production** for security.

## Error Handling

Common errors:

### No Backups Found
```
❌ No database backups found
```
**Fix**: Download backups first: `php artisan database:download production`

### Database Connection Failed
```
❌ Cannot connect to database
```
**Fix**: Verify `.env` database credentials

### MySQL Access Denied
```
ERROR 1045 (28000): Access denied
```
**Fix**: Check `DB_USERNAME` and `DB_PASSWORD` in `.env`

### Database Not Found
```
ERROR 1049 (42000): Unknown database
```
**Fix**: Create database first: `mysql -e "CREATE DATABASE myapp_local"`

### Insufficient Privileges
```
ERROR 1142 (42000): DROP command denied
```
**Fix**: Grant DROP privilege to database user

## Manual Restore

To manually restore a backup:

```bash
# Drop and recreate database
mysql -u root -p -e "DROP DATABASE IF EXISTS myapp_local"
mysql -u root -p -e "CREATE DATABASE myapp_local"

# Restore from backup
mysql -u root -p myapp_local < .deploy/downloads/backups/backup.sql

# Run migrations
php artisan migrate --force

# Clear cache
php artisan optimize:clear
```

## Use Cases

### Local Development
```bash
# Get fresh production data
php artisan database:download production
php artisan database:restore --latest
```

### Testing Migrations
```bash
# Restore to test migration
php artisan database:restore --latest --no-migrate

# Test migration
php artisan migrate

# If issues, restore again
php artisan database:restore --latest --no-migrate
```

### Bug Investigation
```bash
# Restore production data to investigate bugs
php artisan database:download production
php artisan database:restore --latest
```

### Team Onboarding
```bash
# New developer setup
php artisan database:download staging
php artisan database:restore --latest
```

## Related Commands

- [`database:download`](database-download.md) - Download backup from server
- [`database:backup`](database-backup.md) - Create backup on server
- [`database:upload`](database-upload.md) - Upload backup to server

## Tips

- **Backup before restore** to preserve current data
- **Use staging data** for local development when possible
- **Test restore process** regularly to verify backups work
- **Clean sensitive data** from development backups
- **Document restore procedures** for team members

## Architecture

This command is standalone and does not use DatabaseAction. It directly:
- Scans local filesystem for backups
- Executes `mysql` command via Laravel Process
- Manages local database restoration

See: `src/Commands/DatabaseRestoreCommand.php`
