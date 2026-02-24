# Laravel Deployer Package

Rsync-based zero-downtime deployment automation for Laravel.

---

## Projects Using This Package

**Any changes MUST be tested across all projects.**

| Project | Path | Assets |
|---------|------|--------|
| TimeBox | `/Users/mshaf/Developer/Sites/timebox/web` | `npm run build` |
| ThePayrollApp | `/Users/mshaf/Developer/Sites/thepayrollapp` | `npm run prod` (Mix) |
| WestWindSupplies | `/Users/mshaf/Developer/Sites/westwindsupplies-dev` | `npm run build` |

All use dual repository config (local symlink + GitHub VCS fallback). Sync changes: `./sync-to-projects.sh`.

**GitHub**: `git@github.com:hmshafeeq/laravel-deployer.git`

---

## Commands

```bash
# Deploy
php artisan deployer:release staging              # Full deployment
php artisan deployer:release production

# Sync (no new release — patches current)
php artisan deployer:sync staging                  # Full rsync checksum scan
php artisan deployer:sync staging --dirty          # Uncommitted changes only
php artisan deployer:sync staging --since=abc123   # Since a commit
php artisan deployer:sync staging --branch         # vs main branch

# Other
php artisan deployer:rollback staging              # Rollback to previous release
php artisan deployer:server clear staging           # Clear caches on server
php artisan deployer:server provision               # Provision new server
php artisan deployer:setup install                  # Install/regenerate config
php artisan deployer:setup init staging             # Migrate existing site
php artisan deployer:setup keygen                   # Generate SSH keys
php artisan deployer:db backup staging              # Backup database
php artisan deployer:db download staging             # Download backup
php artisan deployer:db restore                     # Restore backup locally
php artisan deployer:db list                        # List backups
```

---

## Deployment Flow (21 Steps)

```
BEFORE SYMLINK:
  1-3.  Lock → Setup structure → Create release dir
  4-6.  Build assets locally → Show diff → Confirm
  7-8.  Copy previous release → Rsync changes
  9-15. Shared symlinks → Composer → Permissions → Migrations → beforeSymlink hooks → Storage link

SYMLINK SWITCH:
  16.   Symlink current → /releases/YYYYMM.N/

AFTER SYMLINK:
  17-21. Log success → Health check → Cleanup old releases → Post-deploy hooks → Receipt

OPTIMIZATION (automatic):
  Restart PHP-FPM → Reload Nginx → Reload Supervisor → artisan optimize
```

---

## Optimal Configuration

```json
"beforeSymlink": ["php artisan optimize:clear"]
```

**Do NOT put** `optimize`, individual `:clear` commands, or `:cache` commands in `beforeSymlink` — optimization runs automatically AFTER symlink with fresh OPcache.

**Filament projects** — add `"php artisan filament:optimize"` to `postDeploy` (needs post-service-restart).

`postDeploy` should ONLY contain commands not handled by OptimizeAction (e.g., `filament:optimize`, `queue:restart`, app-specific commands).

---

## Sync Mode

Syncs files to the **existing/current release** without creating a new release.

### Smart Step Skipping (git-based strategies)

| Step | Skipped when... |
|------|-----------------|
| `assets:build` | No JS/CSS/Blade in diff |
| `composer:install` | No `composer.lock` in diff |
| `permissions:fix` | No new files (only mods) |
| `artisan:migrate` | No `database/migrations/` files |

**When to use sync**: Quick hotfixes, config changes, small code tweaks, view updates.
**When to use full deploy**: New features, breaking changes, vendor updates, schema migrations.

**Important**: No rollback for sync (changes current release directly). Composer runs with `--no-scripts --no-plugins`.

---

## Consistency Requirements

When modifying this package:

- **Stub changes** (`stubs/`): Update `deploy.json` in all 3 projects
- **Config changes**: `php artisan vendor:publish --tag=laravel-deployer-config --force` in each project
- **Recipe changes** (`recipe/deploy.php`): Affects all projects immediately (symlinked) — test in staging first
- **New features/commands**: Test in all three projects

---

## File Locations

```
.deploy/
├── deploy.json              # Main deployment config
├── .env.local               # Local test deployment
├── .env.staging             # Staging server credentials
├── .env.production          # Production server credentials
├── .env.*.example           # Example files (tracked in git)
```

## Gitignore Integration

Files in `.gitignore` are automatically excluded from deployment. Set `rsync.useGitignore: false` to disable.

## Testing

```bash
cd /Users/mshaf/Developer/Sites/timebox/web/packages/laravel-deployer
vendor/bin/pest                                    # Package tests
php artisan deployer staging --dry-run             # Test deploy in each project
```

## Troubleshooting

- **"View Not Found" after deploy**: Set `"beforeSymlink": ["php artisan optimize:clear"]` and redeploy
- **Cache permission errors**: `sudo chgrp -R www-data bootstrap/cache && sudo chmod -R 2775 bootstrap/cache`
- **Deploy too slow**: Check for redundant `:clear`/`:cache` commands in hooks
