# Laravel Deployer

## Critical

- **MUST** test changes across all consuming projects before merging
- **NEVER** put `optimize`, `:clear`, or `:cache` commands in `beforeSymlink` — optimization runs automatically AFTER symlink

## Commands

```bash
php artisan deployer:release staging          # Full deployment
php artisan deployer:sync staging             # Rsync to current release (no new release)
php artisan deployer:sync staging --dirty     # Uncommitted changes only
php artisan deployer:rollback staging         # Rollback to previous release
php artisan deployer:server clear staging     # Clear caches on server
php artisan deployer:server provision         # Provision new server
php artisan deployer:setup install            # Install/regenerate config
php artisan deployer:setup init staging       # Migrate existing site
php artisan deployer:setup keygen             # Generate SSH keys
php artisan deployer:db backup staging        # Backup database
php artisan deployer:db backup staging --install  # Backup + download + install locally
php artisan deployer:db download staging      # Download backup
php artisan deployer:db install               # Install backup to local database
```

## Deployment Flow

```
BEFORE SYMLINK: Lock → Structure → Release dir → Build assets → Diff → Confirm →
  Copy prev release → Rsync → Shared symlinks → Composer → Permissions →
  Migrations → beforeSymlink hooks → Storage link
SYMLINK: current → /releases/YYYYMM.N/
AFTER SYMLINK: Log → Health check → Cleanup → postDeploy hooks → Receipt
AUTO: Restart PHP-FPM → Reload Nginx → Reload Supervisor → artisan optimize
```

## Configuration Gotchas

```json
"beforeSymlink": ["php artisan optimize:clear"]
```

- `postDeploy`: Only commands NOT handled by OptimizeAction (e.g., `filament:optimize`, `queue:restart`)
- Filament projects: add `"php artisan filament:optimize"` to `postDeploy`

## Sync Mode

Patches current release directly — **no rollback available**.

| Step | Skipped when... |
|------|-----------------|
| `assets:build` | No JS/CSS/Blade in diff |
| `composer:install` | No `composer.lock` in diff |
| `permissions:fix` | No new files (only mods) |
| `artisan:migrate` | No `database/migrations/` files |

## Consistency

- **Stub changes** (`stubs/`): Update `deploy.json` in all consuming projects
- **Config changes**: `php artisan vendor:publish --tag=laravel-deployer-config --force` per project
- **New features/commands**: Test in all consuming projects

## File Layout

```
.deploy/
├── deploy.json           # Main deployment config
├── .env.{environment}    # Server credentials (not tracked)
├── .env.*.example        # Example files (tracked)
```

Files in `.gitignore` are automatically excluded from rsync. Disable: `rsync.useGitignore: false`.

## Testing

```bash
vendor/bin/pest                                   # Package tests
php artisan deployer:release staging --dry-run    # Test deploy per project
```

## Troubleshooting

- **"View Not Found" after deploy**: Add `"php artisan optimize:clear"` to `beforeSymlink`
- **Cache permission errors**: `sudo chgrp -R www-data bootstrap/cache && sudo chmod -R 2775 bootstrap/cache`
- **Deploy too slow**: Remove redundant `:clear`/`:cache` commands from hooks
