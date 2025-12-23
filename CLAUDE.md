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

If you modify any stub files (e.g., `deploy.yaml.stub`, `.env.*.example`):

```bash
# After updating stubs, manually update existing deploy.yaml in each project
# OR inform users to re-run the installer (will overwrite their config)
```

**Projects to update:**
- `/Users/mshaf/Developer/Sites/timebox/web/.deploy/deploy.yaml`
- `/Users/mshaf/Developer/Sites/thepayrollapp/.deploy/deploy.yaml`
- `/Users/mshaf/Developer/Sites/westwindsupplies-latest/deploy.yaml`

### 2. Config Changes (`config/`)

If you modify `config/laravel-deployer.php`:

```bash
# Republish config in each project
php artisan vendor:publish --tag=laravel-deployer-config --force
```

### 3. New Features or Commands

When adding new Artisan commands or features:
- Test in all three projects
- Update deploy.yaml examples if new configuration options are added
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

## Quick Reference

### Commands
```bash
php artisan deploy staging          # Deploy to staging
php artisan deploy production       # Deploy to production
php artisan deploy:rollback         # Rollback release
php artisan deploy:key-generate     # Generate SSH keys
php artisan deployer:migrate staging    # Migrate existing site to deployer structure
php artisan deployer:clear          # Clear caches on server
php artisan laravel-deployer:install    # Install/regenerate config
php artisan laravel-deployer:provision  # Provision new server
```

### File Locations
```
.deploy/
├── deploy.yaml              # Main deployment config
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
php artisan deploy staging --dry-run
```

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
