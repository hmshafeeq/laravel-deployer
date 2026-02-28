# OrbStack VM Integration Test Harness

This folder contains production-like integration tests for `shaf/laravel-deployer`
using OrbStack Ubuntu VMs instead of Docker containers.

## Scenarios

- `fresh`: Provision a fresh Ubuntu VM, deploy twice, rollback, diagnose, backup, sync.
- `existing`: Seed a flat Laravel install, migrate with `deployer:setup init`, then deploy/rollback.
- `drifted` (`drift` alias): Introduce server drift, verify diagnose failure/fix path, and recover.

## Prerequisites

- macOS with OrbStack installed and running
- `orbctl`, `php`, `composer`, `npm`, `ssh`, `scp`, `rsync`

## Usage

```bash
.harness/run-tests.sh --scenario all
```

Options:

- `--scenario all|fresh|existing|drifted|drift|comma,list`
- `--clean` (default) recreate `.harness/laravel-app`
- `--reuse-app` reuse existing test app
- `--reuse` reuse scenario VM names and keep them after run
- `--keep-vm` keep created VMs for debugging
- `--distro ubuntu:jammy` change OrbStack image
- `--arch arm64|amd64` set VM architecture
- `--vm-user <username>` set OrbStack default user to create/use
- `--vm-prefix <prefix>` VM name prefix

## Artifacts

Each run stores machine-readable and log artifacts in:

- `.harness/artifacts/orbstack/<run-id>/results.json`
- `.harness/artifacts/orbstack/<run-id>/scenarios/<scenario>/*.log`
- `.harness/artifacts/orbstack/<run-id>/scenarios/<scenario>/deployer-state.tgz`
- `.harness/artifacts/orbstack/latest` (symlink to latest run)

## Consuming Projects Mode

Run downstream validation across consuming projects:

```bash
.harness/run-tests.sh \
  --consuming-project /abs/path/project-a \
  --consuming-project /abs/path/project-b
```

Or from a file:

```bash
.harness/run-tests.sh --consuming-projects-file .harness/consumers.txt
```

Only run consuming project validation:

```bash
.harness/run-tests.sh --consuming-only --consuming-project /abs/path/project-a
```

Each consuming project check:

- enforces fixture policy on `.deploy/deploy.json` (`beforeSymlink` forbidden hooks)
- points Composer to this local package via path repository
- runs `php artisan deployer:release staging --dry-run --no-confirm`
