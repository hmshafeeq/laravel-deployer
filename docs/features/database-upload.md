# Database Upload Command

Upload a database backup file from your local machine to any remote server.

## Command

```bash
php artisan database:upload {file}
```

## Arguments

- `file` - Path to the local SQL backup file to upload

## Options

- `--target=USER@HOST` - Target server (e.g., `deploy@server.com`)
- `--key=PATH` - Custom SSH private key path
- `--port=PORT` - Custom SSH port (default: 22)
- `--path=PATH` - Remote destination path (default: `/tmp`)

## Usage Examples

```bash
# Upload to specific server
php artisan database:upload backup.sql --target=deploy@newserver.com

# Upload with custom SSH key
php artisan database:upload backup.sql --target=deploy@server.com --key=~/.ssh/custom_key

# Upload to custom path
php artisan database:upload backup.sql --target=deploy@server.com --path=/var/backups

# Upload to custom port
php artisan database:upload backup.sql --target=deploy@server.com --port=2222
```

## What It Does

The database upload command:

1. **Validates Local File** - Ensures backup file exists
2. **Prompts for Target** - Asks for server details if not provided
3. **Validates SSH Connection** - Tests connection to target server
4. **Creates Remote Directory** - Ensures destination path exists
5. **Uploads via SCP** - Transfers backup file to server
6. **Displays Success** - Shows remote file path

## Use Cases

### Database Migration
```bash
# Download from old server
php artisan database:download old-production

# Upload to new server
php artisan database:upload .deploy/downloads/backups/backup.sql \
  --target=deploy@newserver.com
```

### Restore Production from Backup
```bash
# Upload local backup to production
php artisan database:upload my-backup.sql \
  --target=deploy@production.com \
  --path=/var/www/app/shared/backups
```

### Share Data Between Environments
```bash
# Upload staging data to development server
php artisan database:upload staging-backup.sql \
  --target=deploy@dev.company.com
```

### Emergency Restore
```bash
# Upload working backup to recover from failure
php artisan database:upload last-known-good.sql \
  --target=deploy@production.com
```

## Supported File Formats

- ✅ `.sql` - Plain SQL dump
- ✅ `.sql.gz` - Gzip compressed SQL
- ✅ Any text file containing SQL statements

## Prerequisites

- Local backup file exists
- SSH access to target server
- SSH key authentication configured (or password access)
- Sufficient disk space on target server

## SSH Key Configuration

### Using Default Key
```bash
# Uses ~/.ssh/id_rsa by default
php artisan database:upload backup.sql --target=user@server.com
```

### Using Custom Key
```bash
# Specify custom key
php artisan database:upload backup.sql \
  --target=user@server.com \
  --key=~/.ssh/deploy_key
```

### Multiple Keys
```bash
# Use different keys for different servers
php artisan database:upload backup.sql \
  --target=user@prod.com \
  --key=~/.ssh/prod_key

php artisan database:upload backup.sql \
  --target=user@staging.com \
  --key=~/.ssh/staging_key
```

## File Size Considerations

### Small Files (<100MB)
- Upload immediately
- No special handling needed

### Medium Files (100MB-1GB)
- May take several minutes
- Ensure stable connection
- Monitor progress

### Large Files (>1GB)
- Consider compression before upload
- Use rsync with resume capability
- Upload during off-peak hours

## Compression

Compress large backups before uploading:

```bash
# Compress locally
gzip backup.sql

# Upload compressed file
php artisan database:upload backup.sql.gz --target=user@server.com

# Decompress on server
ssh user@server.com "gunzip /tmp/backup.sql.gz"
```

## Error Handling

Common errors:

### File Not Found
```
File not found: backup.sql
```
**Fix**: Verify file path is correct

### SSH Connection Failed
```
Permission denied (publickey)
```
**Fix**: Verify SSH key: `ssh -i ~/.ssh/key user@server.com`

### Insufficient Disk Space
```
No space left on device
```
**Fix**: Free up space on target server

### Network Timeout
```
Connection timed out
```
**Fix**: Check network connection, retry, or use rsync

## Manual Upload

To manually upload via SCP:

```bash
# Basic upload
scp backup.sql user@server.com:/tmp/

# Custom key
scp -i ~/.ssh/custom_key backup.sql user@server.com:/tmp/

# Custom port
scp -P 2222 backup.sql user@server.com:/tmp/

# With compression
gzip -c backup.sql | ssh user@server.com "cat > /tmp/backup.sql.gz"
```

## Security Considerations

### Sensitive Data
- ✅ Use encrypted connections (SSH/SCP)
- ✅ Verify target server identity
- ✅ Delete uploaded files after use
- ✅ Restrict file permissions on server

### SSH Keys
- ✅ Use separate keys for different environments
- ✅ Protect private keys (chmod 600)
- ✅ Use passphrase-protected keys
- ✅ Rotate keys regularly

## After Upload

Once uploaded, you can restore on the target server:

```bash
# SSH to target server
ssh user@server.com

# Restore database
mysql -u username -p database_name < /tmp/backup.sql

# Clean up
rm /tmp/backup.sql
```

## Related Commands

- [`database:backup`](database-backup.md) - Create backup on server
- [`database:download`](database-download.md) - Download backup from server
- [`database:restore`](database-restore.md) - Restore backup to local database

## Tips

- **Verify upload success** by checking file size on server
- **Use compression** for large files to save bandwidth
- **Clean up uploads** after restoration to save disk space
- **Document server access** for emergency situations
- **Test upload process** before emergencies

## Architecture

This command uses:
- **DatabaseAction** - `upload()` method
- **CommandService** - SCP file transfer
- **SSH authentication** - Key-based or password

See: `src/Actions/DatabaseAction.php:65`
