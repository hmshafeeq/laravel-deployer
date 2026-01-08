# Laravel Deployer Package

Laravel package for deployment automation with rsync-based zero-downtime deployments.

---

## Projects Using This Package

This package is used by multiple projects. **Any changes to configuration, stubs, or default behavior MUST be reflected in all projects to maintain consistency.**

| Project | Path | Application Name |
|---------|------|------------------|
| TimeBox | `/Users/mshaf/Developer/Sites/timebox/web` | TimeBox |
| ThePayrollApp | `/Users/mshaf/Developer/Sites/thepayrollapp` | ThePayrollApp |
| WestWindSupplies | `/Users/mshaf/Developer/Sites/westwindsupplies-latest` | WestwindSupplies |

### Distribution Setup

All projects use a dual repository configuration:

```json
"repositories": [
    {
        "type": "path",
        "url": "/Users/mshaf/Developer/Sites/timebox/web/packages/laravel-deployer",
        "options": {"symlink": true}
    },
    {
        "type": "vcs",
        "url": "git@github.com:hmshafeeq/laravel-deployer.git"
    }
]
```

- **Local development**: Symlinks to this folder (changes reflected immediately)
- **Teammates/CI**: Falls back to GitHub repository

---

## Consistency Requirements

When making changes to this package, ensure consistency across all projects:

### 1. Stub Changes (`stubs/`)

If you modify any stub files (e.g., `deploy.json.stub`, `.env.*.example`):

```bash
# After updating stubs, manually update existing deploy.json in each project
# OR inform users to re-run the installer (will overwrite their config)
```

**Projects to update:**
- `/Users/mshaf/Developer/Sites/timebox/web/.deploy/deploy.json`
- `/Users/mshaf/Developer/Sites/thepayrollapp/.deploy/deploy.json`
- `/Users/mshaf/Developer/Sites/westwindsupplies-latest/deploy.json`

### 2. Config Changes (`config/`)

If you modify `config/laravel-deployer.php`:

```bash
# Republish config in each project
php artisan vendor:publish --tag=laravel-deployer-config --force
```

### 3. New Features or Commands

When adding new Artisan commands or features:
- Test in all three projects
- Update deploy.json examples if new configuration options are added
- Document breaking changes

### 4. Recipe Changes (`recipe/deploy.php`)

Changes to the deployment recipe affect all projects immediately (symlinked). Test deployments in staging before production.

---

## Project-Specific Configurations

### TimeBox (`timebox/web`)
- Standard Laravel 12 + Filament setup
- Uses `npm run build` for assets

### ThePayrollApp
- Laravel 11 with Laravel Mix
- Uses `npm run prod` for assets (not `npm run build`)

### WestWindSupplies
- Laravel 12 + Filament setup
- Uses `npm run build` for assets

---

## Local Development Workflow

### Syncing Changes to Other Projects

When testing package changes locally before pushing to GitHub:

```bash
cd /Users/mshaf/Developer/Sites/timebox/web/packages/laravel-deployer
./sync-to-projects.sh
```

This script uses rsync to copy the package to:
- `/Users/mshaf/Developer/Sites/thepayrollapp/vendor/shaf/laravel-deployer`
- `/Users/mshaf/Developer/Sites/westwindsupplies-latest/vendor/shaf/laravel-deployer`

**Note:** Changes are temporary. Teammates and CI will get updates via `composer update` from GitHub.

---

## Quick Reference

### Commands
```bash
# Main deployment
php artisan deployer staging              # Deploy to staging
php artisan deployer production           # Deploy to production

# Release management
php artisan deployer:release rollback staging   # Rollback to previous release

# Server management
php artisan deployer:server clear staging       # Clear caches on server
php artisan deployer:server provision           # Provision new server

# Setup and initialization
php artisan deployer:setup install              # Install/regenerate config
php artisan deployer:setup init staging         # Migrate existing site to deployer structure
php artisan deployer:setup keygen               # Generate SSH keys

# Database operations
php artisan deployer:db backup staging          # Backup database on server
php artisan deployer:db download staging        # Download backup from server
php artisan deployer:db restore                 # Restore backup locally
php artisan deployer:db list                    # List available local backups
```

### File Locations
```
.deploy/
├── deploy.json              # Main deployment config
├── .env.local               # Local test deployment
├── .env.staging             # Staging server credentials
├── .env.production          # Production server credentials
├── .env.*.example           # Example files (tracked in git)
```

### Testing Changes

Before pushing changes to GitHub:
```bash
# Run package tests
cd /Users/mshaf/Developer/Sites/timebox/web/packages/laravel-deployer
vendor/bin/pest

# Test deploy command in each project
cd /path/to/project
php artisan deployer staging --dry-run
```

---

## Optimal Configuration

### ❌ Wrong (Redundant & Slow)

```json
"beforeSymlink": [
  "php artisan cache:clear",
  "php artisan config:clear",   // ← Redundant (cache:clear already does this)
  "php artisan route:clear",    // ← Redundant
  "php artisan view:clear",     // ← Redundant
  "php artisan optimize"        // ← Wrong timing (runs after symlink anyway)
]
```

**Problems:**
1. **Redundant clears**: Individual :clear commands are redundant with `optimize:clear`
2. **Redundant optimize**: OptimizeAction runs `optimize` automatically AFTER symlink with fresh OPcache
3. **Wrong timing**: Caching before symlink uses stale OPcache and may cause view errors
4. **Wasted time**: ~10-15 seconds per deployment

### ✅ Correct (Minimal & Fast)

```json
"beforeSymlink": [
  "php artisan optimize:clear"
]
```

**Why?**
1. `optimize:clear` clears ALL caches (config, route, view, event, app cache)
2. Optimization happens automatically AFTER symlink switch
3. Services restart BEFORE optimization ensures fresh OPcache
4. No manual `view:clear` needed after deployment

**Note:** `cache:clear` only clears the application cache (Redis/file store), NOT compiled views. Use `optimize:clear` to clear everything.

### Deployment Flow (21 Steps)

```
BEFORE SYMLINK (New Release):
├─ 1. Lock deployment
├─ 2. Setup deployment structure
├─ 3. Create release directory
├─ 4. Build assets locally (npm run build)
├─ 5. Show sync diff (compare local vs current release)
├─ 6. Confirm changes
├─ 7. Copy previous release (cp -rp, excludes vendor/node_modules)
├─ 8. Rsync files (only transfers changes)
├─ 9. Create shared symlinks (storage, .env)
├─ 10. Install Composer dependencies
├─ 11. Fix permissions (single SSH batch)
├─ 12. Run database migrations
├─ 13. Link .dep directory
├─ 14. Run beforeSymlink commands (cache:clear)
└─ 15. Create storage symlink

SYMLINK SWITCH:
└─ 16. Symlink current → /releases/YYYYMM.N/

AFTER SYMLINK:
├─ 17. Log deployment success
├─ 18. Verify health (if configured)
├─ 19. Cleanup old releases
├─ 20. Run post-deploy hooks
└─ 21. Generate deployment receipt

OPTIMIZATION (OptimizeAction):
├─ Restart PHP-FPM (clears OPcache)
├─ Reload Nginx
├─ Reload Supervisor
└─ Run artisan optimize
```

### Why This Works

**Before symlink:**
- Clearing caches ensures clean slate
- No caching yet → no stale paths

**After symlink:**
- Services restart first → OPcache cleared
- Optimization runs with fresh OPcache → correct cached paths
- All cached files reference CURRENT symlink (correct release)

### Special Cases

#### Filament Projects (TimeBox, WestWindSupplies)

```json
"beforeSymlink": [
  "php artisan cache:clear"
],
"postDeploy": [
  "php artisan filament:optimize"
]
```

**Why postDeploy?** Filament optimization needs to run AFTER services restart.

#### Laravel Mix Projects (ThePayrollApp)

```json
"beforeSymlink": [
  "php artisan cache:clear"
]
```

**Note:** Laravel Mix uses `npm run prod` (not `npm run build`). Configure in `deploy.json` assets section.

### Validation Warnings

The package validates both `beforeSymlink` and `postDeploy` configurations and shows warnings:

#### beforeSymlink Warnings

```
⚠️  Redundant: `artisan optimize` detected in beforeSymlink
   Optimization runs automatically AFTER symlink with fresh OPcache
   Recommendation: Remove `optimize` from beforeSymlink

⚠️  Redundant: Individual :clear commands with cache:clear
   `cache:clear` already clears config, route, view, and event caches
   Recommendation: Remove individual :clear commands
```

#### postDeploy Warnings

```
⚠️  Redundant: `view:clear` detected in postDeploy
   The OptimizeAction already clears and rebuilds caches after service restart
   Recommendation: Remove cache-related commands from postDeploy
```

**Redundant postDeploy commands:**
- `cache:clear` - redundant (optimize clears and rebuilds)
- `view:clear` - redundant (optimize handles views)
- `config:clear` / `config:cache` - redundant (optimize handles config)
- `route:clear` / `route:cache` - redundant (optimize handles routes)
- `event:clear` / `event:cache` - redundant (optimize handles events)
- `optimize` - redundant (runs automatically after service restart)

**Action:** Update your `deploy.json` to remove redundant commands.

### postDeploy Best Practices

The `postDeploy` array should ONLY contain commands that are NOT handled by OptimizeAction:

```json
"postDeploy": [
  "php artisan filament:optimize",   // ✅ Filament-specific (not in artisan optimize)
  "php artisan queue:restart",       // ✅ Queue restart (not in artisan optimize)
  "php artisan custom:command"       // ✅ Application-specific commands
]
```

**Do NOT add these to postDeploy:**
```json
"postDeploy": [
  "php artisan cache:clear",         // ❌ Redundant
  "php artisan config:cache",        // ❌ Redundant
  "php artisan route:cache",         // ❌ Redundant
  "php artisan view:cache",          // ❌ Redundant
  "php artisan optimize"             // ❌ Redundant (runs automatically)
]
```

**Why?** The OptimizeAction runs `artisan optimize` AFTER services restart. This command already:
1. Clears and rebuilds config cache
2. Clears and rebuilds route cache
3. Clears and rebuilds view cache
4. Clears and rebuilds event cache

Adding these commands to `postDeploy` doubles the work and wastes ~10-15 seconds per deployment.

---

## Troubleshooting

### "View Not Found" or Wrong View After Deployment

**Cause:** View cache was built before services restarted, using stale OPcache.

**Solution:**
1. Update `deploy.json`:
   ```json
   "beforeSymlink": ["php artisan cache:clear"]
   ```
2. Redeploy
3. Should no longer need manual `view:clear` after deployment

### Cache Permission Errors

**Cause:** Bootstrap cache doesn't have correct permissions.

**Solution:** The deployer automatically fixes permissions on retry. If it persists:
```bash
ssh user@server
cd /path/to/release
sudo chgrp -R www-data bootstrap/cache
sudo chmod -R 2775 bootstrap/cache
```

### Deployment Takes Too Long

**Check for redundant commands:**
- Multiple `:clear` commands (use only `cache:clear`)
- Multiple `:cache` commands in `beforeSymlink` (remove all)
- `optimize` in both `beforeSymlink` and automatic post-symlink (remove from `beforeSymlink`)

**Expected times:**
- beforeSymlink: ~2-5 seconds (cache:clear only)
- optimize: ~10-15 seconds (after symlink)

---

## GitHub Repository

**URL**: `git@github.com:hmshafeeq/laravel-deployer.git`

After making local changes, push to GitHub so teammates can receive updates:
```bash
cd /Users/mshaf/Developer/Sites/timebox/web/packages/laravel-deployer
git add . && git commit -m "feat: description of change"
git push origin main
```

Teammates update via:
```bash
composer update shaf/laravel-deployer
```
