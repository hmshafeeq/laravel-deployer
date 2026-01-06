# Migration Guide

Migrate existing Laravel deployments to the laravel-deployer directory structure.

## Artisan Command (Recommended)

The easiest way to migrate is using the `deployer:migrate` command:

```bash
# Migrate staging (uses deploy.yaml configuration)
php artisan deployer:migrate staging

# Migrate production
php artisan deployer:migrate production

# Options
php artisan deployer:migrate staging --dry-run          # Preview changes
php artisan deployer:migrate staging --force            # Skip confirmation
php artisan deployer:migrate staging --skip-db-backup   # Skip database backup
php artisan deployer:migrate staging --skip-project-backup  # Skip project backup
```

The command reads server credentials from your `deploy.yaml` and `.deploy/.env.*` files.

---

## Shell Script (Alternative)

For advanced use cases or automation, use the shell script directly. Runs from your **local machine** via SSH.

### Overview

The `migrate-to-deployer.sh` script:
1. **Backs up project files** (tar.gz, excludes vendor/node_modules)
2. **Backs up database** (MySQL/MariaDB, auto-detects credentials from .env)
3. **Only proceeds** after both backups succeed
4. **Migrates** to zero-downtime directory structure

**Before:**
```
/var/www/example.com/
├── app/
├── public/
├── storage/
├── .env
└── ...
```

**After:**
```
/var/www/example.com/
├── current -> releases/202512.1
├── releases/
│   └── 202512.1/
│       ├── app/
│       ├── public/
│       ├── storage -> /var/www/example.com/shared/storage
│       ├── .env -> /var/www/example.com/shared/.env
│       └── ...
├── shared/
│   ├── storage/
│   └── .env
└── .dep/
```

## Prerequisites

### Required Deployment Structure

This migration is for **traditional (flat) Laravel deployments** only:

```
/var/www/example.com/          ← Site exists here (NOT in /current or /releases)
├── app/
├── public/                    ← Nginx points here
├── storage/
├── .env
└── ...
```

**Your nginx config should look like this BEFORE migration:**
```nginx
server {
    root /var/www/example.com/public;
    # ...
}
```

If your site already uses `releases/` and `current` symlinks, it's already migrated — do not run this script.

### Server Requirements

1. **SSH key configured** on the server
2. **sudo access** (optional, but recommended for VPS/dedicated servers)
   - **VPS/Dedicated Servers**: Passwordless sudo recommended for full permissions control
   - **Shared Hosting**: Works without sudo (permissions managed by hosting provider)

### Generate SSH Key (if needed)

```bash
# Generate key locally
php artisan deploy:key-generate

# Or use existing key and copy to server
ssh-copy-id ubuntu@your-server.com
```

## Usage

```bash
# Basic usage
./migrate-to-deployer.sh <host> <domain> [options]

# With user@host format
./migrate-to-deployer.sh ubuntu@192.168.1.100 thepayrollapp.com

# With SSH key
./migrate-to-deployer.sh server.com example.com --key=~/.ssh/deploy_key

# Dry run (see what would happen)
./migrate-to-deployer.sh server.com example.com --dry-run
```

### Arguments

| Argument | Description |
|----------|-------------|
| `host` | Server hostname or IP (can include `user@` prefix) |
| `domain` | Domain name (e.g., `thepayrollapp.com`) |

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--user=USER` | `ubuntu` | SSH user |
| `--port=PORT` | `22` | SSH port |
| `--key=PATH` | - | Path to SSH private key |
| `--base-path=PATH` | `/var/www` | Base path for sites |
| `--web-user=USER` | `www-data` | Web server user (nginx/apache) |
| `--deploy-user=USER` | `ubuntu` | User that runs deployments |
| `--backup-path=PATH` | `/var/www/backups` | Backup directory on server |
| `--db-name=NAME` | (auto) | Database name |
| `--db-user=USER` | (auto) | Database user |
| `--db-pass=PASS` | (auto) | Database password |
| `--skip-db-backup` | `false` | Skip database backup |
| `--dry-run` | `false` | Show what would be done |
| `--help` | - | Show help message |

## Examples

### Migrate ThePayrollApp

```bash
# Staging
./migrate-to-deployer.sh ubuntu@35.85.3.156 dev.thepayrollapp.com

# Production
./migrate-to-deployer.sh ubuntu@35.85.3.156 thepayrollapp.com
```

### Migrate with Custom Options

```bash
./migrate-to-deployer.sh 192.168.1.100 myapp.com \
    --user=deployer \
    --key=~/.ssh/deploy_key \
    --base-path=/home/sites \
    --web-user=nginx \
    --deploy-user=deployer
```

### Dry Run First

```bash
# See what would happen without making changes
./migrate-to-deployer.sh server.com example.com --dry-run
```

## What Happens

### Step 1: Pre-flight Checks
- Tests SSH connection
- Verifies site path exists
- Checks if already migrated
- Detects Laravel installation
- Auto-reads database credentials from `.env`
- **Auto-detects sudo availability** (adapts behavior for shared hosting vs VPS)

### Step 2: Backup Project Files
- Creates `/var/www/backups/` directory
- Backs up to `{domain}-files-{timestamp}.tar.gz`
- **Includes hidden files** (`.env`, `.htaccess`, etc.)
- Excludes `vendor/`, `node_modules/`, `.git/`, log files
- **Script stops if backup fails**

### Step 3: Backup Database
- Auto-detects credentials from `.env`:
  - `DB_DATABASE`
  - `DB_USERNAME`
  - `DB_PASSWORD`
- Backs up to `{domain}-database-{timestamp}.sql.gz`
- **Script stops if backup fails**

### Step 4: Migrate Structure
- Creates `releases/YYYYMM.1` directory
- Moves Laravel files to release
- Sets up shared storage with symlinks
- Creates `current` symlink

### Step 5: Set Permissions
- **With sudo (VPS/Dedicated)**: Sets ownership for deploy and web users, configures proper permissions
- **Without sudo (Shared Hosting)**: Sets basic permissions (ownership managed by hosting provider)
- Automatically adapts based on sudo availability

## Release Naming

Releases are named using the format `YYYYMM.N`:
- `202512.1` - First release in December 2025
- `202512.2` - Second release in December 2025
- `202601.1` - First release in January 2026

## Post-Migration

### 1. Update Nginx Configuration

```nginx
# Before
server {
    root /var/www/example.com/public;
    # ...
}

# After
server {
    root /var/www/example.com/current/public;
    # ...
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### 2. Update Local `.deploy/.env.*` Files

```env
DEPLOY_HOST=your-server-ip
DEPLOY_USER=ubuntu
DEPLOY_PATH=/var/www/example.com
DEPLOY_BRANCH=main
```

### 3. Test Deployment

```bash
# Dry run first
php artisan deploy staging --dry-run

# Actual deployment
php artisan deploy staging
```

## Backups

Backups are stored on the server at `/var/www/backups/`:

```
/var/www/backups/
├── thepayrollapp.com-files-20251223-143022.tar.gz
├── thepayrollapp.com-database-20251223-143022.sql.gz
├── dev.thepayrollapp.com-files-20251223-144512.tar.gz
└── dev.thepayrollapp.com-database-20251223-144512.sql.gz
```

### Restore from Backup

```bash
# SSH into server
ssh ubuntu@server.com

# Stop nginx
sudo systemctl stop nginx

# Remove migrated structure
sudo rm -rf /var/www/example.com

# Restore project files
cd /var/www
sudo tar -xzf backups/example.com-files-YYYYMMDD-HHMMSS.tar.gz

# Restore database
gunzip -c backups/example.com-database-YYYYMMDD-HHMMSS.sql.gz | mysql -u root -p database_name

# Restart nginx
sudo systemctl start nginx
```

## Troubleshooting

### SSH Connection Failed

```bash
# Test SSH connection manually
ssh -v ubuntu@server.com

# Check SSH key permissions
chmod 600 ~/.ssh/id_rsa
```

### Permission Denied

```bash
# Ensure SSH user has sudo access
ssh ubuntu@server.com "sudo whoami"

# If prompted for password, add to sudoers:
# ubuntu ALL=(ALL) NOPASSWD:ALL
```

### Database Backup Failed

```bash
# Manually test database connection
ssh ubuntu@server.com "mysql -u DB_USER -p DB_NAME -e 'SELECT 1'"

# Check credentials in .env
ssh ubuntu@server.com "grep DB_ /var/www/example.com/.env"
```

### Symlink Issues

```bash
# Verify symlinks after migration
ssh ubuntu@server.com "ls -la /var/www/example.com/"
ssh ubuntu@server.com "ls -la /var/www/example.com/current/"
```

### Shared Hosting Specific Issues

**Backup Path Permissions**
- Backups are created in `{parent-of-deploy-path}/backups/` (e.g., `/home/username/domains/example.com/backups/`)
- No sudo required - uses regular user permissions

**Permission Warnings**
- On shared hosting, you may see permission warnings during migration
- This is normal - the hosting provider manages file ownership automatically
- The migration will complete successfully without sudo

**Nginx/Apache Configuration**
- On shared hosting, update the document root through your hosting control panel (cPanel, Plesk, etc.)
- Point it to: `{deploy-path}/current/public`
- Example: `/home/username/domains/example.com/public_html/current/public`

## Script Location

The script is located in the package:
```
vendor/shaf/laravel-deployer/scripts/migrate-to-deployer.sh
```

Or run directly from the package:
```bash
./vendor/shaf/laravel-deployer/scripts/migrate-to-deployer.sh server.com example.com
```
