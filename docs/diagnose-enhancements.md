# Diagnose Command Enhancements

## Overview
Enhanced `php artisan deployer:server diagnose --fix` to match the functionality of the standalone permission standardization bash script.

## Changes Made

### 1. Bidirectional Group Membership (NEW)
**File**: `src/Actions/DiagnoseAction.php:165-199`

Previously only checked if deploy user (ubuntu) was in web group (www-data). Now also checks reverse:

- ✅ Deploy user → web group: `ubuntu` in `www-data`
- ✅ Web user → deploy group: `www-data` in `ubuntu` (NEW)

**Fix provided:**
```bash
sudo usermod -aG ubuntu www-data
# Reconnect SSH for changes to take effect
```

### 2. Setgid Enforcement (ENHANCED)
**File**: `src/Actions/DiagnoseAction.php:405-441`

Changed from **warning** to **failure** with automatic fix.

**Fix provided:**
```bash
sudo find {path} -type d -exec chmod g+s \;
```

### 3. Comprehensive Permission Standardization (NEW)
**File**: `src/Actions/DiagnoseAction.php:528-604`

Added two new checks that enforce explicit permission modes (not just adding flags):

#### Directory Permissions
- Expected mode: `2775` (rwxrwsr-x with setgid)
- **Fix provided:**
```bash
sudo find {path} -type d -exec chmod 2775 {} \;
```

#### File Permissions
- Expected mode: `664` (rw-rw-r--)
- **Fix provided:**
```bash
sudo find {path} -type f -exec chmod 664 {} \;
```

### 4. Aggressive Writable Path Enforcement (NEW)
**File**: `src/Actions/DiagnoseAction.php:606-662`

Checks critical Laravel paths for group-writable permissions:
- `shared/storage`
- `current/bootstrap/cache`

**Fix provided:**
```bash
sudo chmod -R g+w {path}
```

## Generated Fix Script Structure

When running `php artisan deployer:server diagnose staging --fix`, the generated script now includes:

```bash
#!/bin/bash
# Permission fix script for staging
# Deploy path: /var/www/example.com
# Generated: 2026-01-07 12:00:00
# Review carefully before running!

set -e

DEPLOY_PATH="/var/www/example.com"
DEPLOY_USER="ubuntu"
WEB_GROUP="www-data"

# Issue 1: Web user in deploy group
# www-data is NOT in ubuntu group (bidirectional membership missing)
sudo usermod -aG ubuntu www-data
echo "✓ Fixed: Web user in deploy group"

# Issue 2: Directories have setgid
# X directories without setgid bit (required for group inheritance)
sudo find /var/www/example.com/current -type d -exec chmod g+s \;
echo "✓ Fixed: Directories have setgid"

# Issue 3: Directory permissions standardized
# X directories need permission normalization
sudo find /var/www/example.com/current -type d -exec chmod 2775 {} \;
echo "✓ Fixed: Directory permissions standardized"

# Issue 4: File permissions standardized
# X files need permission normalization
sudo find /var/www/example.com/current -type f -exec chmod 664 {} \;
echo "✓ Fixed: File permissions standardized"

# Issue 5: Writable: shared/storage
# X items not group-writable
sudo chmod -R g+w /var/www/example.com/shared/storage
echo "✓ Fixed: Writable: shared/storage"

# Issue 6: Writable: bootstrap/cache
# X items not group-writable
sudo chmod -R g+w /var/www/example.com/current/bootstrap/cache
echo "✓ Fixed: Writable: bootstrap/cache"

echo ""
echo "Done! Reconnect SSH if group membership was changed."
```

## Coverage Comparison

| Feature | Bash Script | Before | After |
|---------|-------------|--------|-------|
| ubuntu → www-data membership | ✅ | ✅ | ✅ |
| www-data → ubuntu membership | ✅ | ❌ | ✅ |
| Setgid enforcement | ✅ | ⚠️ (warn only) | ✅ |
| Explicit directory mode (2775) | ✅ | ❌ | ✅ |
| Explicit file mode (664) | ✅ | ❌ | ✅ |
| Ownership normalization | ✅ | ✅ | ✅ |
| Recursive storage writability | ✅ | ⚠️ (ownership only) | ✅ |

## Testing

Added tests in `tests/Feature/ServerCommandTest.php`:
- ✅ Diagnose command registration
- ✅ Environment argument requirement
- ✅ `--fix` and `--full` flag availability

## Usage

```bash
# Quick diagnostic (counts only)
php artisan deployer:server diagnose staging

# Full diagnostic (lists problematic files)
php artisan deployer:server diagnose staging --full

# Generate fix script
php artisan deployer:server diagnose staging --fix

# The fix script is saved to:
# .deploy/fix-permissions-staging.sh
```

## Migration Note

The bash script can now be replaced by:
```bash
php artisan deployer:server diagnose production --fix
scp .deploy/fix-permissions-production.sh user@server:/tmp/
ssh user@server "bash /tmp/fix-permissions-production.sh"
```
