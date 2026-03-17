# Replace spatie/ssh with In-House SSH Implementation

**Date:** 2026-03-17
**Status:** Ready for planning

## What We're Building

A unified `SshService` that replaces spatie/ssh and centralizes ALL remote execution (SSH, SCP, rsync) across the entire package. This eliminates the current fragmentation where 6+ files independently construct shell commands with inconsistent Windows handling.

## Why This Approach

- **PR #29 exposed the problem:** Windows fixes were needed in `CommandService` and `RsyncService`, but the same issues exist in `ServerCommand`, `DatabaseCommand`, `DiffAction`, and `SetupCommand` -- all building SSH/SCP/rsync commands independently
- **spatie/ssh is a thin wrapper** -- only used in `CommandService` for `Ssh::create()` + `.execute()`. We already bypass it for most operations
- **Single responsibility:** One class owns OS detection, path resolution, quoting, and transport -- no more scattered `exec()`/`proc_open()`/`Process::run()` calls

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Scope | Full replacement | Every command uses the same SSH service |
| Windows SSH | Native `ssh.exe` for SSH/SCP, WSL for rsync | rsync has no Windows native binary |
| PR #29 | Merge first, then refactor | Respects colleague's contribution, gives us a working baseline |
| Multiplexing | Keep on Unix, skip on Windows | ControlMaster not supported on Windows OpenSSH |

## Architecture

### SshService responsibilities

- **`execute(string|array $commands)`** -- Run commands on remote server (replaces `$ssh->execute()`)
- **`upload(string $local, string $remote)`** -- SCP upload (replaces raw `scp` calls in ServerCommand, DatabaseCommand)
- **`download(string $remote, string $local)`** -- SCP download
- **`rsync(string $source, string $dest, array $options)`** -- Rsync with proper quoting (replaces RsyncService shell building)
- **`test(string $command)`** -- Remote test (replaces CommandService::test())
- OS-aware command building (Windows native SSH + WSL rsync vs Unix)
- Connection config (host, port, user, key, timeout, multiplexing)
- Consistent quoting and escaping across platforms

### What gets removed/simplified

- `spatie/ssh` dependency from `composer.json`
- `CommandService` SSH initialization and Windows bypass methods
- Raw `exec()`/`proc_open()` in `ServerCommand`
- Duplicate rsync command building in `RsyncService`, `DatabaseCommand`, `DiffAction`
- `ssh-copy-id` usage in `SetupCommand` (doesn't exist on Windows -- use manual key append)

### Files affected

| File | Change |
|------|--------|
| `SshService.php` (new) | Core SSH/SCP/rsync execution |
| `CommandService.php` | Delegate to SshService, remove spatie/ssh |
| `RsyncService.php` | Delegate rsync to SshService |
| `ServerCommand.php` | Replace raw exec()/proc_open() with SshService |
| `DatabaseCommand.php` | Replace raw rsync/scp with SshService |
| `DiffAction.php` | Replace raw rsync dry-run with SshService |
| `SetupCommand.php` | Replace ssh-copy-id/ssh-keygen with SshService |
| `Constants/Commands.php` | May move SSH options into SshService |
| `composer.json` | Remove `spatie/ssh` |

## Windows Execution Strategy

```
SSH/SCP:  C:\Windows\System32\OpenSSH\ssh.exe / scp.exe
          (Process array, no shell interpretation)

rsync:    wsl rsync -e "ssh.exe ..." /mnt/c/... user@host:...
          (WSL wrapper, convert Windows paths to /mnt/c/ format)

No rsync: Fall back gracefully with clear error message
```

## Uncovered PR #29 Gaps (to fix in refactor)

1. `CommandService::test()` -- still calls `$this->ssh->execute()` directly
2. `ServerCommand` -- 5 methods with raw SSH/SCP via `exec()`
3. `DatabaseCommand::handleDownload()` -- rsync with single-quoted `-e`
4. `DatabaseCommand::executeUpload()` -- SCP via `Process::run()`
5. `DiffAction` -- `mktemp -d`, grep pipes, single-quoted rsync
6. `SetupCommand::copyKeyToServer()` -- `ssh-copy-id` absent on Windows

## Open Questions

_None -- all resolved during brainstorming._
