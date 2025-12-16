# Laravel Deployer - Architecture Guide

A comprehensive technical guide to understanding the internal architecture, data flow, and design patterns of Laravel Deployer.

---

## Table of Contents

1. [High-Level Overview](#high-level-overview)
2. [Directory Structure](#directory-structure)
3. [Core Components](#core-components)
4. [Deployment Flow](#deployment-flow)
5. [Server Directory Structure](#server-directory-structure)
6. [Data Flow Diagrams](#data-flow-diagrams)
7. [Class Relationships](#class-relationships)
8. [Extension Points](#extension-points)
9. [Configuration Deep Dive](#configuration-deep-dive)
10. [Command Reference](#command-reference)

---

## High-Level Overview

Laravel Deployer uses an **action-based architecture** with atomic symlink swapping for zero-downtime deployments.

```
+------------------------------------------------------------------+
|                        DEPLOYMENT OVERVIEW                        |
+------------------------------------------------------------------+

LOCAL MACHINE                              REMOTE SERVER
+------------------+                       +----------------------+
|                  |                       |                      |
|  Laravel App     |      SSH + rsync      |   /var/www/app/      |
|  Source Code     | --------------------> |                      |
|                  |                       |   current/ ----+     |
|  npm run build   |                       |                |     |
|  (assets)        |                       |   releases/    |     |
|                  |                       |   +- 202501.1  |     |
+------------------+                       |   +- 202501.2 <+     |
                                           |   +- 202501.3        |
                                           |                      |
                                           |   shared/            |
                                           |   +- storage/        |
                                           |   +- .env            |
                                           |                      |
                                           +----------------------+

ZERO-DOWNTIME: Atomic symlink swap (ln -nfs)
```

### Key Principles

1. **Atomic Deployments**: New release fully prepared before becoming active
2. **Shared Resources**: Storage and .env persist across releases
3. **Instant Rollback**: Previous releases remain intact
4. **Deployment Locking**: Prevents concurrent deployments
5. **Comprehensive Logging**: All operations tracked

---

## Directory Structure

```
laravel-deployer/
|
+-- config/
|   +-- laravel-deployer.php     # Package configuration defaults
|
+-- helpers/
|   +-- deployment.php           # Utility functions (format_bytes, etc.)
|
+-- src/
|   |
|   +-- Actions/                 # High-level workflow orchestrators
|   |   +-- DeployAction.php         # Main deployment (17 steps)
|   |   +-- RollbackAction.php       # Rollback to previous
|   |   +-- DiffAction.php           # File sync differences
|   |   +-- DatabaseAction.php       # Database operations
|   |   +-- HealthCheckAction.php    # Server health verification
|   |   +-- OptimizeAction.php       # Post-deploy optimization
|   |   +-- NotificationAction.php   # Slack/Discord alerts
|   |   |
|   |   +-- Database/            # Database sub-actions
|   |   +-- Deployment/          # Deployment step sub-actions
|   |   +-- HealthCheck/         # Health check sub-actions
|   |   +-- Maintenance/         # Service restart sub-actions
|   |   +-- Notification/        # Notification sub-actions
|   |   +-- Service/             # Service management sub-actions
|   |
|   +-- Services/                # Core service layer
|   |   +-- CommandService.php       # Local/remote command execution
|   |   +-- DeploymentService.php    # Release & lock management
|   |   +-- ConfigService.php        # YAML config loading
|   |   +-- RsyncService.php         # File synchronization
|   |   +-- HealthCheckService.php   # Health checks
|   |
|   +-- Commands/                # Artisan console commands
|   |   +-- DeployCommand.php        # php artisan deploy
|   |   +-- RollbackCommand.php      # php artisan deploy:rollback
|   |   +-- DatabaseBackupCommand.php
|   |   +-- DatabaseDownloadCommand.php
|   |   +-- DatabaseUploadCommand.php
|   |   +-- DatabaseRestoreCommand.php
|   |   +-- InstallCommand.php
|   |   +-- SshKeyGenerateCommand.php
|   |   +-- ProvisionCommand.php
|   |   +-- ClearCommand.php
|   |
|   +-- Data/                    # Immutable data objects
|   |   +-- DeploymentConfig.php     # Full deployment configuration
|   |   +-- ReleaseInfo.php          # Release metadata
|   |   +-- ServerConnection.php     # SSH connection details
|   |   +-- SyncDiff.php             # File changes (new/mod/del)
|   |   +-- TaskResult.php           # Task execution result
|   |
|   +-- Enums/                   # Type-safe enumerations
|   |   +-- Environment.php          # LOCAL, STAGING, PRODUCTION
|   |   +-- TaskStatus.php           # PENDING, RUNNING, etc.
|   |   +-- VerbosityLevel.php       # Output verbosity
|   |
|   +-- Exceptions/              # Custom exceptions
|   |   +-- ConfigurationException.php
|   |   +-- DeploymentException.php
|   |   +-- HealthCheckException.php
|   |   +-- RsyncException.php
|   |   +-- SSHConnectionException.php
|   |   +-- TaskExecutionException.php
|   |
|   +-- Constants/               # Constant values
|   |   +-- Paths.php                # Directory names
|   |   +-- Commands.php             # Shell commands
|   |   +-- Timeouts.php             # Timeout values
|   |
|   +-- Support/Abstract/        # Base classes
|   |   +-- Action.php               # Base action class
|   |
|   +-- LaravelDeployerServiceProvider.php
|
+-- stubs/                       # Configuration templates
+-- tests/                       # Pest test suite
+-- docs/                        # Documentation
```

---

## Core Components

### Architecture Overview

```
+------------------------------------------------------------------+
|                      COMPONENT ARCHITECTURE                       |
+------------------------------------------------------------------+

                    +------------------+
                    |  Artisan Command |
                    |  (DeployCommand) |
                    +--------+---------+
                             |
                             v
                    +------------------+
                    |   Main Action    |
                    |  (DeployAction)  |
                    +--------+---------+
                             |
         +-------------------+-------------------+
         |                   |                   |
         v                   v                   v
+----------------+  +----------------+  +----------------+
|  CommandService|  |DeploymentService|  | RsyncService  |
+----------------+  +----------------+  +----------------+
|                |  |                |  |                |
| - remote()     |  | - lock()       |  | - sync()       |
| - local()      |  | - unlock()     |  | - getDiff()    |
| - artisan()    |  | - getReleases()|  | - setExcludes()|
| - fileExists() |  | - generateName |  |                |
+-------+--------+  +-------+--------+  +-------+--------+
        |                   |                   |
        v                   v                   v
+------------------------------------------------------------------+
|                        SSH (Spatie SSH)                           |
|                    Remote Server Execution                        |
+------------------------------------------------------------------+
```

### Service Responsibilities

| Service | Purpose | Key Methods |
|---------|---------|-------------|
| **CommandService** | Execute commands locally/remotely | `remote()`, `local()`, `artisan()`, `fileExists()`, `directoryExists()` |
| **DeploymentService** | Manage releases and locks | `lock()`, `unlock()`, `generateReleaseName()`, `getReleases()`, `getCurrentRelease()` |
| **RsyncService** | Sync files to server | `sync()`, `getDiff()`, `setExcludes()`, `setIncludes()` |
| **ConfigService** | Load configuration | `load()`, `getAvailableEnvironments()` |
| **HealthCheckService** | Verify server health | `checkDiskSpace()`, `checkEndpoints()` |

---

## Deployment Flow

### Complete Deployment Sequence (17 Steps)

```
+------------------------------------------------------------------+
|                    DEPLOYMENT FLOW (17 STEPS)                     |
+------------------------------------------------------------------+

PHASE 1: PREPARATION
+--------------------+
| 1. LOCK DEPLOYMENT |  --> Creates .dep/deploy.lock
+--------------------+      Prevents concurrent deployments
         |
         v
+------------------------+
| 2. SETUP STRUCTURE     |  --> Creates directories if missing:
+------------------------+      releases/, shared/, .dep/
         |                      shared/storage/*, shared/.env
         v
+------------------------+
| 3. CREATE RELEASE      |  --> Generates name: YYYYMM.N (e.g., 202501.3)
+------------------------+      Creates releases/202501.3/
         |
         v

PHASE 2: BUILD & SYNC
+------------------------+
| 4. BUILD ASSETS        |  --> npm run build (local)
+------------------------+      Compiles frontend assets
         |
         v
+------------------------+
| 5. SHOW SYNC DIFF      |  --> rsync --dry-run
+------------------------+      Shows new/modified/deleted files
         |
         v
+------------------------+
| 6. CONFIRM CHANGES     |  --> User approval required
+------------------------+      Extra warnings for production
         |
         v
+------------------------+
| 7. SYNC FILES          |  --> rsync to releases/202501.3/
+------------------------+      Excludes: .git, node_modules, .env

PHASE 3: LINK & CONFIGURE
         |
         v
+------------------------+
| 8. CREATE SHARED LINKS |  --> ln -nfs shared/storage storage
+------------------------+      ln -nfs shared/.env .env
         |
         v
+------------------------+
| 9. SET PERMISSIONS     |  --> chmod 775 writable directories
+------------------------+      bootstrap/cache, storage
         |
         v
+------------------------+
| 10. COMPOSER INSTALL   |  --> composer install --no-dev
+------------------------+       --optimize-autoloader

PHASE 4: DATABASE & FINALIZE
         |
         v
+------------------------+
| 11. FIX PERMISSIONS    |  --> 644 for files, 755 for dirs
+------------------------+      Fixes vendor/, node_modules/
         |
         v
+------------------------+
| 12. RUN MIGRATIONS     |  --> php artisan migrate --force
+------------------------+
         |
         v
+------------------------+
| 13. LINK .DEP DIR      |  --> Links .dep for logging
+------------------------+
         |
         v

PHASE 5: ACTIVATE
+------------------------+
| 14. SYMLINK RELEASE    |  --> ln -nfs releases/202501.3 current
+------------------------+      ATOMIC OPERATION - Zero Downtime!
         |
         v
+------------------------+
| 15. CLEANUP OLD        |  --> Keep last N releases (default: 3)
+------------------------+      Removes oldest releases
         |
         v
+------------------------+
| 16. LOG DEPLOYMENT     |  --> Writes to .dep/deploy.log
+------------------------+      Records user, time, release
         |
         v
+------------------------+
| 17. POST-DEPLOY HOOKS  |  --> Executes .dep/post-deploy.sh
+------------------------+      Custom user scripts

ALWAYS (via finally block):
+------------------------+
| 18. UNLOCK DEPLOYMENT  |  --> Removes .dep/deploy.lock
+------------------------+
```

### Rollback Flow (5 Steps)

```
+------------------------------------------------------------------+
|                       ROLLBACK FLOW (5 STEPS)                     |
+------------------------------------------------------------------+

+--------------------+     +--------------------+
| 1. LOCK DEPLOYMENT | --> | 2. GET RELEASES    |
+--------------------+     +--------------------+
                                    |
                           +--------+--------+
                           |                 |
                           v                 v
                      [current]         [previous]
                      202501.3          202501.2
                                             |
                                             v
                           +--------------------+
                           | 3. VERIFY EXISTS   |
                           +--------------------+
                                    |
                                    v
                           +--------------------+
                           | 4. SYMLINK SWITCH  |
                           +--------------------+
                           |                    |
                           | ln -nfs            |
                           | releases/202501.2  |
                           | current            |
                           |                    |
                           +--------------------+
                                    |
                                    v
                           +--------------------+
                           | 5. LOG & UNLOCK    |
                           +--------------------+

NOTE: Database migrations are NOT rolled back automatically!
      Manual intervention required for schema changes.
```

---

## Server Directory Structure

### Complete Server Layout

```
/var/www/app/                           <-- deploy_path
|
+-- current -> releases/202501.3        <-- Symlink to active release
|
+-- releases/                           <-- All release directories
|   +-- 202501.1/                       <-- Oldest (may be cleaned up)
|   +-- 202501.2/                       <-- Previous (for rollback)
|   +-- 202501.3/                       <-- Current active release
|       |
|       +-- app/
|       +-- bootstrap/
|       |   +-- cache/                  <-- Writable (775)
|       +-- config/
|       +-- database/
|       +-- public/
|       +-- resources/
|       +-- routes/
|       +-- storage -> ../shared/storage  <-- Symlink
|       +-- vendor/
|       +-- .env -> ../shared/.env        <-- Symlink
|       +-- .dep -> ../.dep               <-- Symlink
|       +-- artisan
|       +-- composer.json
|       +-- composer.lock
|
+-- shared/                             <-- Persists across releases
|   +-- .env                            <-- Production environment
|   +-- storage/
|   |   +-- app/
|   |   |   +-- public/
|   |   +-- framework/
|   |   |   +-- cache/
|   |   |   |   +-- data/
|   |   |   +-- sessions/
|   |   |   +-- views/
|   |   +-- logs/
|   |       +-- laravel.log
|   +-- backups/                        <-- Database backups
|       +-- backup-2025-01-15.sql.gz
|       +-- backup-2025-01-16.sql.gz
|
+-- .dep/                               <-- Deployment metadata
    +-- deploy.lock                     <-- Active during deployment
    +-- deploy.log                      <-- Deployment history
    +-- latest_release                  <-- Current release name
    +-- releases.log                    <-- JSON release records
    +-- release_counter/
    |   +-- 202501.txt                  <-- Counter for month
    +-- post-deploy.sh                  <-- Custom hook script
```

### Symlink Relationships

```
SYMLINK STRUCTURE
=================

             +------------------+
             |    current/      |
             +--------+---------+
                      |
                      | symlink
                      v
          +------------------------+
          | releases/202501.3/     |
          +------------------------+
                      |
          +-----------+-----------+
          |                       |
          v                       v
  +---------------+       +---------------+
  | storage/      |       | .env          |
  +-------+-------+       +-------+-------+
          |                       |
          | symlink               | symlink
          v                       v
  +---------------+       +---------------+
  | shared/       |       | shared/.env   |
  | storage/      |       +---------------+
  +---------------+


ATOMIC SYMLINK SWAP
===================

BEFORE:
  current -> releases/202501.2

COMMAND:
  ln -nfs releases/202501.3 current

  -n : treat LINK_NAME as a normal file if it's a symlink
  -f : remove existing destination files
  -s : make symbolic link

AFTER:
  current -> releases/202501.3

This is a single atomic filesystem operation = ZERO DOWNTIME
```

---

## Data Flow Diagrams

### Configuration Loading

```
CONFIGURATION LOADING
=====================

.deploy/deploy.yaml          .deploy/.env.production
+------------------+         +------------------+
| hosts:           |         | DEPLOY_HOST=... |
|   production:    |         | DEPLOY_USER=... |
|     hostname:... | ------> | DEPLOY_PATH=... |
|     deploy_path: |         +--------+--------+
+--------+---------+                  |
         |                            |
         v                            v
+------------------------------------------+
|              ConfigService               |
|  +------------------------------------+  |
|  | - Parses YAML                      |  |
|  | - Loads .env credentials           |  |
|  | - Merges environment overrides     |  |
|  | - Validates configuration          |  |
|  +------------------------------------+  |
+------------------------------------------+
                    |
                    v
         +--------------------+
         | DeploymentConfig   |
         +--------------------+
         | readonly class     |
         | - environment      |
         | - hostname         |
         | - remoteUser       |
         | - deployPath       |
         | - branch           |
         | - keepReleases     |
         | - composerOptions  |
         | - rsyncExcludes    |
         | - etc...           |
         +--------------------+
```

### File Synchronization

```
FILE SYNC (RSYNC) FLOW
======================

LOCAL                                        REMOTE
+------------------+                         +------------------+
|                  |                         |                  |
| Project Root     |       rsync             | releases/        |
| /Users/dev/app/  | ----------------------> | 202501.3/        |
|                  |                         |                  |
| - app/           |  -rzc --delete          | - app/           |
| - bootstrap/     |  --compress             | - bootstrap/     |
| - config/        |  --exclude=.git         | - config/        |
| - database/      |  --exclude=node_modules | - database/      |
| - public/        |  --exclude=.env         | - public/        |
| - resources/     |  --exclude=tests        | - resources/     |
| - routes/        |  --exclude=storage      | - routes/        |
|                  |                         |                  |
+------------------+                         +------------------+

RSYNC FLAGS:
  -r : recursive
  -z : compress during transfer
  -c : checksum (skip based on checksum, not mod-time/size)
  --delete : delete extraneous files from destination
  --delete-after : delete after transfer, not before
  --compress : compress file data during transfer

SSH OPTIONS:
  -A : agent forwarding
  -o ControlMaster=auto : connection multiplexing
  -o ControlPersist=60 : keep connection open 60s
```

### Command Execution

```
COMMAND EXECUTION FLOW
======================

                    +-------------------+
                    |   DeployAction    |
                    +--------+----------+
                             |
                             | $this->cmd->remote("...")
                             v
                    +-------------------+
                    |  CommandService   |
                    +--------+----------+
                             |
          +------------------+------------------+
          |                                     |
          v                                     v
+-------------------+               +-------------------+
|    Local Mode     |               |   Remote Mode     |
+-------------------+               +-------------------+
| Symfony Process   |               | Spatie SSH        |
| runs command on   |               | runs command on   |
| local machine     |               | remote server     |
+-------------------+               +-------------------+
          |                                     |
          v                                     v
+-------------------+               +-------------------+
|                   |               |                   |
| Process::run()    |               | Ssh::create()     |
|                   |               |   ->execute()     |
|                   |               |                   |
+-------------------+               +-------------------+
```

---

## Class Relationships

### Action Pattern

```
ACTION PATTERN (SINGLE RESPONSIBILITY)
======================================

                     +----------------+
                     |     Action     |
                     |   (abstract)   |
                     +-------+--------+
                             |
     +-----------------------+-----------------------+
     |           |           |           |           |
     v           v           v           v           v
+--------+ +----------+ +--------+ +--------+ +----------+
| Deploy | | Rollback | | Health | | Notify | | Database |
| Action | |  Action  | | Check  | | Action | |  Action  |
+--------+ +----------+ | Action | +--------+ +----------+
     |                   +--------+
     |
     | composes
     v
+-------------------+-------------------+-------------------+
|                   |                   |                   |
v                   v                   v                   v
+-------------+ +-------------+ +-------------+ +-------------+
| Deployment  | |   Command   | |   Rsync     | |    Diff     |
|   Service   | |   Service   | |   Service   | |   Action    |
+-------------+ +-------------+ +-------------+ +-------------+


EACH ACTION:
- Has single execute() method
- Coordinates multiple services
- Handles its own errors
- Provides clear output
```

### Data Objects

```
DATA OBJECT HIERARCHY
=====================

+-------------------+     +-------------------+
| DeploymentConfig  |     |   ReleaseInfo     |
+-------------------+     +-------------------+
| readonly class    |     | readonly class    |
|                   |     |                   |
| + environment     |     | + name            |
| + hostname        |     | + createdAt       |
| + remoteUser      |     | + user            |
| + deployPath      |     | + branch          |
| + branch          |     |                   |
| + port            |     | + toLogEntry()    |
| + keepReleases    |     +-------------------+
| + composerOptions |
| + showDiff        |     +-------------------+
| + confirmChanges  |     |    SyncDiff       |
| + rsyncExcludes   |     +-------------------+
| + rsyncIncludes   |     | readonly class    |
| + isLocal         |     |                   |
+-------------------+     | + newFiles        |
                          | + modifiedFiles   |
+-------------------+     | + deletedFiles    |
| ServerConnection  |     |                   |
+-------------------+     | + hasChanges()    |
| + host            |     | + isEmpty()       |
| + user            |     +-------------------+
| + port            |
| + identityFile    |     +-------------------+
+-------------------+     |   TaskResult      |
                          +-------------------+
                          | + status          |
                          | + output          |
                          | + duration        |
                          | + isSuccess()     |
                          +-------------------+
```

---

## Extension Points

### Custom Post-Deployment Hooks

```
POST-DEPLOYMENT HOOKS
=====================

Location: /var/www/app/.dep/post-deploy.sh

+------------------------------------------------------------------+
| #!/bin/bash                                                       |
| # Custom post-deployment tasks                                    |
|                                                                   |
| # Clear application caches                                        |
| cd /var/www/app/current                                          |
| php artisan config:cache                                          |
| php artisan route:cache                                           |
| php artisan view:cache                                            |
|                                                                   |
| # Restart queue workers                                           |
| sudo supervisorctl restart all                                    |
|                                                                   |
| # Warm up caches                                                  |
| curl -s https://example.com > /dev/null                          |
|                                                                   |
| # Notify monitoring service                                       |
| curl -X POST https://api.newrelic.com/deployment                 |
|                                                                   |
| echo "Post-deployment complete!"                                  |
+------------------------------------------------------------------+

EXECUTION:
  - Runs after symlink switch
  - Only if file exists and is executable
  - Failures logged but don't fail deployment
```

### Health Check Endpoints

```
HEALTH CHECK CONFIGURATION
==========================

deploy.yaml:
+------------------------------------------------------------------+
| hosts:                                                            |
|   production:                                                     |
|     hostname: example.com                                         |
|     health_check_endpoints:                                       |
|       - url: https://example.com/health                          |
|         status: 200                                               |
|       - url: https://example.com/api/status                      |
|         status: 200                                               |
+------------------------------------------------------------------+

HEALTH CHECK FLOW:
+--------------+     +---------------+     +-------------+
| Pre-deploy   | --> | Check disk    | --> | Check RAM   |
| health check |     | space (>90%)  |     | available   |
+--------------+     +---------------+     +-------------+
                            |
                            v
                     +---------------+
                     | Check each    |
                     | endpoint      |
                     | (HTTP status) |
                     +---------------+
                            |
                   +--------+--------+
                   |                 |
                   v                 v
            [All Pass]        [Any Fail]
                   |                 |
                   v                 v
            Continue         Abort with
            deployment       clear message
```

### Notification Channels

```
NOTIFICATION FLOW
=================

                    +-------------------+
                    | Deployment Event  |
                    | (success/failure) |
                    +--------+----------+
                             |
                             v
                    +-------------------+
                    | NotificationAction|
                    +--------+----------+
                             |
         +-------------------+-------------------+
         |                                       |
         v                                       v
+-------------------+               +-------------------+
|      Slack        |               |     Discord       |
+-------------------+               +-------------------+
| Webhook POST      |               | Webhook POST      |
| - Release name    |               | - Release name    |
| - Environment     |               | - Environment     |
| - User            |               | - User            |
| - Duration        |               | - Duration        |
| - Status          |               | - Status          |
+-------------------+               +-------------------+

ENVIRONMENT VARIABLES:
  DEPLOY_SLACK_WEBHOOK=https://hooks.slack.com/...
  DEPLOY_DISCORD_WEBHOOK=https://discord.com/api/webhooks/...
```

---

## Configuration Deep Dive

### Complete Configuration Schema

```yaml
# .deploy/deploy.yaml

hosts:
  staging:
    hostname: staging.example.com     # Server hostname/IP
    remote_user: deploy               # SSH user
    deploy_path: /var/www/staging     # Deployment base path
    branch: main                      # Git branch
    port: 22                          # SSH port (optional)
    identity_file: ~/.ssh/id_rsa      # SSH key (optional)
    health_check_endpoints:           # Custom health checks
      - url: https://staging.example.com/health
        status: 200

  production:
    hostname: example.com
    remote_user: deploy
    deploy_path: /var/www/production
    branch: production
    port: 22

config:
  # Release management
  keep_releases: 3                    # Number of releases to keep

  # Composer settings
  composer_options: '--no-dev --optimize-autoloader'

  # Diff and confirmation
  show_diff: true                     # Show file differences
  confirm_changes: true               # Require confirmation
  show_upload_progress: true          # Show progress indicator
  diff_display_limit: 20              # Max files per category

  # Rsync configuration
  rsync:
    exclude:                          # Files/dirs to exclude
      - .git/
      - node_modules/
      - .env
      - tests/
      - storage/
      - .deploy/
    include:                          # Files/dirs to include
      - app/
      - bootstrap/
      - config/
      - database/
      - public/
      - resources/
      - routes/
      - vendor/
      - composer.json
      - composer.lock
      - artisan
```

### Environment Variables (.deploy/.env.production)

```bash
# Server connection
DEPLOY_HOST=example.com
DEPLOY_USER=deploy
DEPLOY_PORT=22
DEPLOY_PATH=/var/www/production
DEPLOY_BRANCH=production

# SSH configuration
DEPLOY_SSH_KEY=~/.ssh/deploy_key

# PHP configuration
DEPLOY_PHP_PATH=/usr/bin/php
DEPLOY_PHP_TIMEOUT=900

# Composer
DEPLOY_COMPOSER_OPTIONS='--no-dev --optimize-autoloader'

# Releases
DEPLOY_KEEP_RELEASES=3

# Rsync
DEPLOY_RSYNC_TIMEOUT=900

# Database backups
DEPLOY_BACKUP_KEEP=3
DEPLOY_BACKUP_TIMEOUT=1800

# Health checks
DEPLOY_HEALTH_CHECK=true
DEPLOY_HEALTH_CHECK_RETRIES=3
DEPLOY_HEALTH_CHECK_DELAY=5
DEPLOY_HEALTH_CHECK_TIMEOUT=30

# Service restarts
DEPLOY_RESTART_PHP_FPM=true
DEPLOY_RESTART_NGINX=true
DEPLOY_RESTART_SUPERVISOR=true

# Notifications
DEPLOY_NOTIFICATIONS=true
DEPLOY_SLACK_WEBHOOK=https://hooks.slack.com/services/...
DEPLOY_DISCORD_WEBHOOK=https://discord.com/api/webhooks/...
```

---

## Command Reference

### Quick Reference Table

```
+-------------------------------------------------------------------------+
|                           COMMAND REFERENCE                              |
+-------------------------------------------------------------------------+
| Command                      | Description                               |
+------------------------------+-------------------------------------------+
| deploy {env}                 | Deploy to environment                     |
|   --no-confirm              | Skip confirmation prompt                  |
|   --skip-health-check       | Skip pre-deploy health checks            |
|                              |                                           |
| deploy:rollback {env}        | Rollback to previous release             |
|   --no-confirm              | Skip confirmation prompt                  |
|                              |                                           |
| database:backup {env}        | Create database backup on server         |
|   --select                  | Interactive server selection             |
|                              |                                           |
| database:download {env}      | Download latest backup locally           |
|   --select                  | Interactive backup selection             |
|                              |                                           |
| database:upload {file}       | Upload backup to server                  |
|   --target=user@host        | Specify target server                    |
|                              |                                           |
| database:restore             | Restore local database from backup       |
|   --latest                  | Use most recent backup                   |
|                              |                                           |
| deploy:key-generate {id}     | Generate SSH key pair                    |
|   --name=key_name           | Custom key name                          |
|   --force                   | Overwrite existing key                   |
|                              |                                           |
| laravel-deployer:install     | Initialize deployment configuration      |
|                              |                                           |
| laravel-deployer:provision   | Provision Ubuntu server                  |
|   --host=hostname           | Server hostname                          |
|   --user=username           | SSH user                                 |
|   --key=path                | SSH key path                             |
|   --create-user             | Create deployment user                   |
|   --deploy-user=name        | Deployment username                      |
|   --php-version=8.3         | PHP version                              |
|   --nodejs-version=20       | Node.js version                          |
|   --with-mysql              | Install MySQL                            |
|   --with-postgresql         | Install PostgreSQL                       |
|   --with-redis              | Install Redis                            |
|   --non-interactive         | Run without prompts                      |
|                              |                                           |
| deployer:clear {env}         | Clear caches on server                   |
+-------------------------------------------------------------------------+
```

### Typical Workflow

```
TYPICAL DEPLOYMENT WORKFLOW
===========================

1. INITIAL SETUP
   +------------------------------------------------------------------+
   | $ composer require shaf/laravel-deployer                         |
   | $ php artisan laravel-deployer:install                          |
   | $ php artisan deploy:key-generate deploy@example.com            |
   +------------------------------------------------------------------+

2. CONFIGURE
   +------------------------------------------------------------------+
   | $ nano .deploy/deploy.yaml        # Edit hosts and config        |
   | $ cp .deploy/.env.production.example .deploy/.env.production    |
   | $ nano .deploy/.env.production    # Add credentials              |
   +------------------------------------------------------------------+

3. FIRST DEPLOYMENT
   +------------------------------------------------------------------+
   | $ php artisan deploy staging      # Test on staging first        |
   | $ ssh deploy@staging "nano /var/www/staging/shared/.env"        |
   | $ php artisan deploy staging      # Re-deploy with .env          |
   +------------------------------------------------------------------+

4. PRODUCTION DEPLOYMENT
   +------------------------------------------------------------------+
   | $ php artisan deploy production   # With confirmation            |
   +------------------------------------------------------------------+

5. IF SOMETHING GOES WRONG
   +------------------------------------------------------------------+
   | $ php artisan deploy:rollback production                         |
   +------------------------------------------------------------------+

6. DATABASE OPERATIONS
   +------------------------------------------------------------------+
   | $ php artisan database:backup production                         |
   | $ php artisan database:download production                       |
   | $ php artisan database:restore --latest                         |
   +------------------------------------------------------------------+
```

---

## Release Naming Convention

```
RELEASE NAME FORMAT: YYYYMM.N
=============================

Examples:
  202501.1  - First release in January 2025
  202501.2  - Second release in January 2025
  202501.15 - 15th release in January 2025
  202502.1  - First release in February 2025

Storage:
  .dep/release_counter/202501.txt  - Contains: "15"
  .dep/release_counter/202502.txt  - Contains: "1"

Benefits:
  - Chronologically sortable
  - Easily parseable
  - No naming conflicts
  - Monthly reset for readability
```

---

## Safety Features Summary

```
SAFETY FEATURES
===============

+------------------------------------------------------------------+
| FEATURE                | PROTECTION                               |
+------------------------------------------------------------------+
| Deployment Lock        | Prevents concurrent deployments          |
| File .dep/deploy.lock  | Contains username of deployer            |
+------------------------------------------------------------------+
| Confirmation Prompts   | Requires 'yes' before changes            |
| Production warnings    | Extra warnings for deletions             |
+------------------------------------------------------------------+
| Health Checks          | Verifies server resources                |
| Pre-deploy checks      | Disk space, memory, endpoints            |
+------------------------------------------------------------------+
| Atomic Symlink         | Single operation switch                  |
| ln -nfs command        | Zero downtime guaranteed                 |
+------------------------------------------------------------------+
| Release Preservation   | Old releases kept for rollback           |
| Configurable count     | Default: 3 releases                      |
+------------------------------------------------------------------+
| Comprehensive Logging  | All operations logged                    |
| .dep/deploy.log        | Who, what, when                          |
+------------------------------------------------------------------+
| Finally Block Unlock   | Lock always released                     |
| Even on errors         | No stuck deployments                     |
+------------------------------------------------------------------+
| Diff Preview           | See exactly what changes                 |
| Color-coded output     | New (green), Modified (yellow), Del (red)|
+------------------------------------------------------------------+
```

---

## Troubleshooting Guide

```
COMMON ISSUES & SOLUTIONS
=========================

ISSUE: Deployment stuck locked
SOLUTION:
  ssh deploy@server "rm /var/www/app/.dep/deploy.lock"

ISSUE: Permission denied during rsync
SOLUTION:
  ssh deploy@server "sudo chown -R deploy:deploy /var/www/app"

ISSUE: SSH connection timeout
SOLUTION:
  - Check SSH key: ssh-add ~/.ssh/your_key
  - Test connection: ssh -v deploy@server
  - Check firewall on server

ISSUE: Composer install fails
SOLUTION:
  - Ensure enough memory: check swap
  - Check PHP version on server
  - Verify composer is installed

ISSUE: Migrations fail
SOLUTION:
  - Ensure .env is configured in shared/
  - Check database credentials
  - Verify database server is accessible

ISSUE: Health check fails
SOLUTION:
  - Check endpoint URLs are correct
  - Verify SSL certificates
  - Check server disk space (>90% triggers warning)
```

---

*This document provides comprehensive technical documentation for Laravel Deployer. For basic usage, see the [README](../README.md). For feature-specific documentation, see the [features directory](./features/).*
