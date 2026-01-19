# Laravel Deployer

[![License](https://img.shields.io/packagist/l/shaf/laravel-deployer)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/shaf/laravel-deployer)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-11.x%20%7C%2012.x-red)](https://laravel.com)

A Laravel package for deployment automation with rsync-based zero-downtime deployments.

## Features

- **Zero-downtime deployments** - Atomic symlink switching ensures no downtime
- **Rsync-based file sync** - Fast, incremental file transfers with diff preview
- **Gitignore integration** - Automatically excludes files from `.gitignore`
- **Multi-environment support** - Deploy to local, staging, and production environments
- **Environment inheritance** - Environments can extend other environments
- **Release management** - Keep multiple releases with instant rollback capability
- **Database operations** - Backup, download, upload, and restore database backups
- **Server provisioning** - Provision fresh Ubuntu servers with PHP, Nginx, Node.js, and more
- **Deployment hooks** - Run custom commands at 14 different points during deployment
- **Diagnostic tools** - Diagnose deployment issues and permission problems
- **SSH key generation** - Generate and manage SSH keys for passwordless deployments
- **Notification support** - Slack and Discord notifications for deployment status
- **Health checks** - Verify deployments with HTTP health check endpoints

## Requirements

- PHP >= 8.2
- Laravel 11.x or 12.x
- SSH access to deployment servers
- rsync installed on local and remote machines

## Installation

```bash
composer require shaf/laravel-deployer
```

The service provider is auto-discovered. Run the setup command to generate configuration files:

```bash
php artisan deployer:setup install
```

This creates:
```
.deploy/
├── deploy.json              # Main deployment configuration (tracked in git)
├── .env.staging.example     # Example staging credentials
├── .env.production.example  # Example production credentials
└── .env.local.example       # Example for local deployments
```

## Quick Start

### 1. Edit deploy.json

Configure your deployment settings in `.deploy/deploy.json`:

```json
{
  "keepReleases": 3,
  "environments": {
    "staging": {
      "deployPath": "/var/www/staging"
    },
    "production": {
      "deployPath": "/var/www/production"
    }
  },
  "beforeSymlink": [
    "php artisan optimize:clear"
  ]
}
```

### 2. Create environment files

```bash
cp .deploy/.env.staging.example .deploy/.env.staging
```

Edit `.deploy/.env.staging`:
```env
DEPLOY_HOST=staging.example.com
DEPLOY_USER=deployer
DEPLOY_IDENTITY_FILE=~/.ssh/id_rsa
```

### 3. Deploy

```bash
php artisan deployer staging
```

---

## Configuration Reference

### deploy.json Structure

```json
{
  "$schema": "./vendor/shaf/laravel-deployer/stubs/deploy.schema.json",

  "keepReleases": 3,
  "phpBinary": "php",
  "copyVendor": true,

  "display": { ... },
  "ssh": { ... },
  "rsync": { ... },
  "composer": { ... },
  "assets": { ... },
  "permissions": { ... },
  "healthCheck": { ... },

  "environments": { ... },

  "beforeSymlink": [],
  "afterSymlink": [],
  "postDeploy": [],
  "hooks": { ... }
}
```

---

### Global Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `keepReleases` | int | `3` | Number of releases to keep on server |
| `phpBinary` | string | `"php"` | Path to PHP binary on server |
| `copyVendor` | bool | `true` | Copy vendor/ from previous release (saves ~40s) |
| `skipPermissionFix` | bool | `false` | Skip permission fixing (if server umask is configured) |
| `backupBeforeMigrate` | bool | `false` | Create database backup before migrations |
| `maintenanceMode` | bool | `false` | Enable maintenance mode during deployment |
| `maintenanceSecret` | string | `null` | Secret to bypass maintenance mode |
| `healthCheckUrl` | string | `null` | URL for post-deployment health check |
| `branch` | string | auto-detect | Git branch for release logging |

---

### Display Settings

Control what the deployer shows during deployment.

```json
{
  "display": {
    "showDiff": true,
    "showPreview": true,
    "confirmChanges": true,
    "showUploadProgress": true,
    "diffDisplayLimit": 20
  }
}
```

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `showDiff` | bool | `true` | Show files that will be synced |
| `showPreview` | bool | `true` | Show early diff preview before confirmation |
| `confirmChanges` | bool | `true` | Ask for confirmation before deployment |
| `showUploadProgress` | bool | `true` | Show rsync upload progress |
| `diffDisplayLimit` | int | `20` | Max files to show per category in diff |

---

### SSH Settings

```json
{
  "ssh": {
    "strictHostKeyChecking": true
  }
}
```

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `strictHostKeyChecking` | bool | `true` | Enable SSH strict host key checking |

---

### Rsync Settings

Configure file synchronization behavior.

```json
{
  "rsync": {
    "exclude": [
      ".git/",
      "node_modules/",
      "/vendor/",
      "storage/",
      ".env",
      "tests/"
    ],
    "include": [
      "composer.json",
      "composer.lock"
    ],
    "flags": "rzc",
    "options": ["delete", "delete-after", "compress"]
  }
}
```

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `useGitignore` | bool | `true` | Automatically exclude files from `.gitignore` |
| `exclude` | array | `[]` | Additional patterns to exclude from sync |
| `include` | array | `[]` | Patterns to include (processed before excludes) |
| `flags` | string | `"rzc"` | Rsync flags (r=recursive, z=compress, c=checksum) |
| `options` | array | `["delete", "delete-after", "compress"]` | Additional rsync options |

**Note:** Files in your `.gitignore` are automatically excluded from deployment.

**Exclude patterns:**
- Use `/vendor/` (leading slash) to exclude only root vendor, not `public/vendor/`
- Use `vendor/` to exclude all vendor directories anywhere
- Use `*.log` to exclude all log files

---

### Composer Settings

```json
{
  "composer": {
    "options": "--prefer-dist --no-interaction --optimize-autoloader"
  }
}
```

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `options` | string | `"--prefer-dist --no-interaction --optimize-autoloader"` | Composer install options |

**Common production options:**
```json
{
  "composer": {
    "options": "--prefer-dist --no-interaction --no-dev --optimize-autoloader"
  }
}
```

---

### Asset Settings

```json
{
  "assets": {
    "failOnError": true,
    "verify": [
      "public/build/",
      "public/build/manifest.json"
    ]
  }
}
```

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `failOnError` | bool | `true` | Fail deployment if asset build fails |
| `verify` | array | `[]` | Paths to verify exist after sync (warns if missing) |

---

### Permission Settings

```json
{
  "permissions": {
    "webGroup": "www-data",
    "enforceSetgid": true,
    "directoryMode": "2775",
    "fileMode": "664"
  }
}
```

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `webGroup` | string | `"www-data"` | Web server group |
| `enforceSetgid` | bool | `true` | Enforce setgid bit on directories |
| `directoryMode` | string | `"2775"` | Directory permissions (rwxrwsr-x) |
| `fileMode` | string | `"664"` | File permissions (rw-rw-r--) |

---

### Health Check Settings

```json
{
  "healthCheck": {
    "enabled": true,
    "url": "/health",
    "timeout": 10,
    "expectedStatus": 200,
    "retries": 3,
    "retryDelay": 2,
    "endpoints": [
      "/api/status",
      { "url": "/admin", "status": 302 }
    ]
  }
}
```

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `enabled` | bool | `false` | Enable health check verification |
| `url` | string | - | Health check URL (relative or absolute) |
| `timeout` | int | `10` | Request timeout in seconds |
| `expectedStatus` | int | `200` | Expected HTTP status code |
| `retries` | int | `3` | Number of retry attempts |
| `retryDelay` | int | `2` | Delay between retries (seconds) |
| `endpoints` | array | `[]` | Additional endpoints to check |

---

### Service Restart Settings

Control which services restart after deployment.

```json
{
  "requiredServices": ["php-fpm", "nginx"],
  "optionalServices": ["supervisor"]
}
```

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `requiredServices` | array | `["php-fpm", "nginx"]` | Services that MUST restart (fails if they don't) |
| `optionalServices` | array | `["supervisor"]` | Optional services (warns on failure) |

---

## Environment Configuration

### Basic Environment

```json
{
  "environments": {
    "staging": {
      "deployPath": "/var/www/staging"
    },
    "production": {
      "deployPath": "/var/www/production",
      "composer": {
        "options": "--prefer-dist --no-interaction --no-dev --optimize-autoloader"
      }
    }
  }
}
```

### Local Environment

For testing deployments locally:

```json
{
  "environments": {
    "local": {
      "local": true,
      "deployPath": "/tmp/app"
    }
  }
}
```

### Environment Inheritance

Environments can extend other environments using `extends`:

```json
{
  "environments": {
    "production": {
      "deployPath": "/var/www/production",
      "composer": {
        "options": "--no-dev --optimize-autoloader"
      },
      "display": {
        "confirmChanges": true
      }
    },
    "staging": {
      "extends": "production",
      "deployPath": "/var/www/staging",
      "display": {
        "confirmChanges": false
      }
    }
  }
}
```

Staging inherits all production settings but overrides `deployPath` and `confirmChanges`.

---

### Environment Secrets (.env files)

Server credentials are stored in `.deploy/.env.{environment}` files (not tracked in git).

**.deploy/.env.staging:**
```env
DEPLOY_HOST=staging.example.com
DEPLOY_USER=deployer
DEPLOY_IDENTITY_FILE=~/.ssh/id_rsa
DEPLOY_PORT=22
GITHUB_TOKEN=ghp_xxxxxxxxxxxx
```

| Variable | Description |
|----------|-------------|
| `DEPLOY_HOST` | Server hostname or IP |
| `DEPLOY_USER` | SSH username |
| `DEPLOY_IDENTITY_FILE` | Path to SSH private key |
| `DEPLOY_PORT` | SSH port (optional, default: 22) |
| `DEPLOY_PATH` | Override deployPath (optional) |
| `GITHUB_TOKEN` | GitHub token for private packages |

---

## Deployment Hooks

### Quick Hooks (beforeSymlink, afterSymlink, postDeploy)

Simple arrays for common use cases:

```json
{
  "beforeSymlink": [
    "php artisan optimize:clear"
  ],
  "afterSymlink": [
    "php artisan queue:restart"
  ],
  "postDeploy": [
    "php artisan filament:optimize"
  ]
}
```

| Hook | Timing | Behavior on Failure |
|------|--------|---------------------|
| `beforeSymlink` | Before symlink switch | **Aborts deployment** |
| `afterSymlink` | After symlink switch | Warns, continues |
| `postDeploy` | After optimization | Warns, continues |

### Best Practices for Quick Hooks

**beforeSymlink - Clear caches only:**
```json
{
  "beforeSymlink": [
    "php artisan optimize:clear"
  ]
}
```

**Why?** Optimization runs automatically AFTER symlink with fresh OPcache.

**Do NOT add these to beforeSymlink:**
- `php artisan optimize` - runs automatically after symlink
- `php artisan config:cache` - runs as part of optimize
- `php artisan route:cache` - runs as part of optimize
- `php artisan view:cache` - runs as part of optimize

**postDeploy - Application-specific commands:**
```json
{
  "postDeploy": [
    "php artisan filament:optimize",
    "php artisan horizon:terminate"
  ]
}
```

---

### Advanced Hooks

For fine-grained control, use the `hooks` object with 14 hook points:

```json
{
  "hooks": {
    "before:deploy": [
      "local:npm run lint"
    ],
    "after:setup": [],
    "before:build": [],
    "after:build": [
      "local:echo 'Assets built successfully'"
    ],
    "before:sync": [],
    "after:sync": [],
    "before:composer": [],
    "after:composer": [],
    "before:migrate": [
      "php artisan backup:run --only-db"
    ],
    "after:migrate": [],
    "before:symlink": [
      "php artisan optimize:clear"
    ],
    "after:symlink": [
      "php artisan queue:restart"
    ],
    "after:deploy": [
      "notify:slack"
    ],
    "on:failure": [
      "notify:slack"
    ]
  }
}
```

### Hook Points Reference

| Hook | When it Runs | Critical? |
|------|--------------|-----------|
| `before:deploy` | Before deployment starts | Yes |
| `after:setup` | After directory structure created | Yes |
| `before:build` | Before `npm run build` | Yes |
| `after:build` | After assets built | No |
| `before:sync` | Before rsync starts | Yes |
| `after:sync` | After files synced | No |
| `before:composer` | Before `composer install` | Yes |
| `after:composer` | After composer completes | No |
| `before:migrate` | Before database migrations | Yes |
| `after:migrate` | After migrations complete | Yes |
| `before:symlink` | Before symlink switch | Yes |
| `after:symlink` | After symlink switch | No |
| `after:deploy` | After deployment completes | No |
| `on:failure` | When deployment fails | No |

**Critical hooks** abort deployment on failure. Non-critical hooks warn and continue.

### Hook Command Types

```json
{
  "hooks": {
    "before:deploy": [
      "local:npm run test",
      "php artisan config:show app.name",
      "artisan cache:clear",
      "notify:slack"
    ]
  }
}
```

| Prefix | Description | Example |
|--------|-------------|---------|
| `local:` | Run on local machine | `local:npm run test` |
| `artisan ` | Artisan command shortcut | `artisan cache:clear` |
| `notify:` | Send notification | `notify:slack` |
| (none) | Run on remote server | `php artisan migrate` |

---

## Complete Configuration Example

```json
{
  "$schema": "./vendor/shaf/laravel-deployer/stubs/deploy.schema.json",

  "keepReleases": 5,
  "phpBinary": "php",
  "copyVendor": true,

  "display": {
    "showDiff": true,
    "confirmChanges": true,
    "showUploadProgress": true,
    "diffDisplayLimit": 30
  },

  "ssh": {
    "strictHostKeyChecking": true
  },

  "rsync": {
    "exclude": [
      ".git/",
      ".github/",
      "node_modules/",
      "/vendor/",
      "storage/",
      "bootstrap/cache/",
      "tests/",
      ".env",
      ".env.*",
      ".deploy/",
      "*.log",
      ".DS_Store"
    ],
    "include": [
      "composer.json",
      "composer.lock"
    ],
    "flags": "rzc",
    "options": ["delete", "delete-after", "compress"]
  },

  "composer": {
    "options": "--prefer-dist --no-interaction --optimize-autoloader"
  },

  "assets": {
    "failOnError": true,
    "verify": ["public/build/manifest.json"]
  },

  "permissions": {
    "webGroup": "www-data",
    "enforceSetgid": true,
    "directoryMode": "2775",
    "fileMode": "664"
  },

  "environments": {
    "local": {
      "local": true,
      "deployPath": "/tmp/app"
    },
    "staging": {
      "deployPath": "/var/www/staging",
      "display": {
        "confirmChanges": false
      }
    },
    "production": {
      "deployPath": "/var/www/production",
      "composer": {
        "options": "--prefer-dist --no-interaction --no-dev --optimize-autoloader"
      },
      "healthCheck": {
        "enabled": true,
        "url": "/health",
        "retries": 3
      }
    }
  },

  "beforeSymlink": [
    "php artisan optimize:clear"
  ],

  "postDeploy": [
    "php artisan filament:optimize"
  ],

  "hooks": {
    "before:migrate": [
      "php artisan backup:run --only-db"
    ],
    "on:failure": [
      "notify:slack"
    ]
  },

  "requiredServices": ["php-fpm", "nginx"],
  "optionalServices": ["supervisor"]
}
```

---

## Commands

### Deploy

```bash
php artisan deployer staging
php artisan deployer production

# Options
php artisan deployer staging --no-confirm       # Skip confirmation
php artisan deployer staging --skip-preview     # Skip diff preview
php artisan deployer staging --skip-health-check
php artisan deployer staging --dry-run          # Show plan only
php artisan deployer staging --interactive      # Interactive mode
```

### Rollback

```bash
php artisan deployer:release rollback staging
php artisan deployer:release rollback production --no-confirm
```

### Database Operations

```bash
# Create backup on server
php artisan deployer:db backup staging

# Download backup
php artisan deployer:db download staging           # Select from list
php artisan deployer:db download staging --latest  # Latest backup
php artisan deployer:db download staging --backup  # Create & download

# Upload backup
php artisan deployer:db upload --target-server=user@host

# Restore locally
php artisan deployer:db restore
php artisan deployer:db restore --latest
php artisan deployer:db restore --no-migrate

# List local backups
php artisan deployer:db list
```

### Server Management

```bash
# Clear caches
php artisan deployer:server clear staging

# Diagnose deployment
php artisan deployer:diagnose staging
php artisan deployer:diagnose staging --compare

# Diagnose permissions
php artisan deployer:server diagnose staging
php artisan deployer:server diagnose staging --full
php artisan deployer:server diagnose staging --fix

# Provision new server
php artisan deployer:server provision
```

### Setup

```bash
# Install configuration
php artisan deployer:setup install

# Migrate existing site
php artisan deployer:setup init staging
php artisan deployer:setup init staging --dry-run

# Generate SSH keys
php artisan deployer:setup keygen
php artisan deployer:setup keygen user@example.com
```

---

## Deployment Flow

```
BEFORE SYMLINK (New Release):
├─ 1.  Lock deployment
├─ 2.  Run hooks: before:deploy
├─ 3.  Setup deployment structure
├─ 4.  Run hooks: after:setup
├─ 5.  Run hooks: before:build
├─ 6.  Build frontend assets (npm run build)
├─ 7.  Run hooks: after:build
├─ 8.  Show sync diff
├─ 9.  Confirm changes
├─ 10. Copy previous release
├─ 11. Run hooks: before:sync
├─ 12. Rsync files
├─ 13. Run hooks: after:sync
├─ 14. Create shared symlinks (storage, .env)
├─ 15. Run hooks: before:composer
├─ 16. Install Composer dependencies
├─ 17. Run hooks: after:composer
├─ 18. Fix permissions
├─ 19. Run hooks: before:migrate
├─ 20. Run database migrations
├─ 21. Run hooks: after:migrate
├─ 22. Link .dep directory
├─ 23. Run beforeSymlink commands
├─ 24. Run hooks: before:symlink
└─ 25. Create storage symlink

SYMLINK SWITCH:
└─ 26. Symlink current -> /releases/YYYYMM.N/

AFTER SYMLINK:
├─ 27. Run hooks: after:symlink
├─ 28. Run afterSymlink commands
├─ 29. Log deployment success
├─ 30. Verify health (if configured)
├─ 31. Cleanup old releases
├─ 32. Restart services (php-fpm, nginx, supervisor)
├─ 33. Run artisan optimize
├─ 34. Run postDeploy commands
├─ 35. Run hooks: after:deploy
└─ 36. Generate deployment receipt
```

---

## Server Provisioning

Laravel Deployer includes a comprehensive server provisioning system for fresh Ubuntu servers.

### Quick Provision

```bash
php artisan deployer:server provision
```

### Non-Interactive Provisioning

```bash
php artisan deployer:server provision \
    --host=your-server.com \
    --user=ubuntu \
    --key=/path/to/ssh/key \
    --create-user \
    --deploy-user=deployer \
    --php-version=8.3 \
    --nodejs-version=20 \
    --with-mysql \
    --with-redis \
    --non-interactive
```

### What Gets Installed

- **Nginx** - Web server with optimized Laravel configuration
- **PHP** - With FPM, CLI, and all common extensions
- **Node.js** - With npm and Yarn
- **Composer** - Latest version
- **MySQL/PostgreSQL/Redis** - Optional databases
- **Supervisor** - For queue workers
- **UFW Firewall** - Security hardening
- **Swap Space** - For servers with limited RAM

---

## Troubleshooting

### SSH Connection Issues

```bash
# Test connection
ssh deploy@yourserver.com

# Verify SSH key
ssh-add -l
```

### Permission Issues

```bash
# On server
sudo chown -R deploy:www-data /var/www/app
chmod -R 775 /var/www/app/shared/storage
```

### Deployment Locked

```bash
# Remove lock file
ssh deploy@server "rm /var/www/app/.dep/deploy.lock"
```

---

## Testing

```bash
vendor/bin/pest
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests: `vendor/bin/pest`
5. Submit a pull request

## License

MIT - See [LICENSE](LICENSE) for details.

## Credits

- Built with [Spatie SSH](https://github.com/spatie/ssh)
- Inspired by [Deployer](https://deployer.org/)
