---
title: "refactor: Replace spatie/ssh with in-house SshService"
type: refactor
status: completed
date: 2026-03-17
origin: docs/brainstorms/2026-03-17-INHOUSE-SSH-BRAINSTORM.md
---

# Replace spatie/ssh with In-House SshService

## Overview

Replace the `spatie/ssh` dependency with a unified `SshService` that centralizes all remote execution (SSH, SCP, rsync) across the package. Currently 6+ files independently construct shell commands with inconsistent SSH options, port handling, timeouts, and zero Windows support. This refactor consolidates everything into one cross-platform service.

## Problem Statement

PR #29 exposed that Windows compatibility fixes were needed in `CommandService` and `RsyncService`, but identical issues exist in `ServerCommand`, `DatabaseCommand`, `DiffAction`, and `SetupCommand`. The root cause is architectural: there is no single SSH abstraction.

**Current state — 6 independent SSH execution patterns:**

| File | Method | Port? | Key? | Host Key? | Timeout | Multiplexing |
|------|--------|-------|------|-----------|---------|--------------|
| `CommandService` | spatie/ssh | Yes | Yes | Configurable | Configurable | Yes (path A) |
| `RsyncService` | `Process::fromShellCommandline()` | Yes | Yes | Configurable | `Timeouts::RSYNC` | Yes (path B) |
| `ServerCommand` | `exec()`/`proc_open()` | Yes | Yes | Hardcoded off | None | No |
| `DatabaseCommand` | `Process::run()` | **No** | Partial | Implicit off | 3600s | No |
| `DiffAction` | `Process::fromShellCommandline()` | Yes | Yes | Configurable | 300s hardcoded | No |
| `SetupCommand` | `Process::run()` | **No** | No | N/A | 60s | No |

**Bugs this reveals (exist today, not just Windows):**
- `DatabaseCommand::handleDownload()` ignores SSH port — non-standard port = silent failure
- `DatabaseCommand::executeUpload()` has no port support at all
- `SetupCommand::copyKeyToServer()` ignores port
- Two different multiplexing control socket paths (CommandService vs RsyncService) — no connection reuse between them
- Four different `~` expansion implementations

## Proposed Solution

A single `SshService` class that owns all SSH transport: command execution, SCP transfers, and rsync syncing. Platform-specific logic (Windows native SSH + WSL rsync vs Unix) is encapsulated inside, invisible to callers.

**Architecture:**

```
DeploymentConfig (readonly DTO)
       │
       ▼
SshService (new — owns all remote transport)
├── ssh(string|array $commands): SshResult
├── sshWithOutput(string|array $commands, Closure $callback): SshResult
├── scp(string $local, string $remote, string $direction): SshResult
├── rsync(string $source, string $dest, array $options): SshResult
├── rsyncDryRun(string $source, string $dest, array $options): SshResult
├── test(string $command): bool
├── testConnection(): bool
├── multiplexing control (enable/disable, cleanup)
└── platform detection (Windows/Unix/WSL)
       │
       ▼
CommandService (delegates SSH to SshService, keeps output/logging)
RsyncService (delegates rsync to SshService, keeps diff parsing/filtering)
ServerCommand (delegates SSH/SCP to SshService)
DatabaseCommand (delegates rsync/SCP to SshService)
DiffAction (delegates rsync dry-run to SshService)
SetupCommand (delegates ssh-copy-id equivalent to SshService)
```

## Technical Approach

### SshService Design

```php
class SshService
{
    // Config
    private string $host;
    private string $user;
    private ?int $port;
    private ?string $identityFile;
    private bool $strictHostKeyChecking;
    private int $timeout;
    private bool $multiplexingEnabled;
    private ?string $controlPath;
    private bool $isWindows;

    // Factory
    public static function fromConfig(DeploymentConfig $config): static;
    public static function fromArray(array $config): static; // For ServerCommand provision

    // SSH execution
    public function ssh(string|array $commands): SshResult;
    public function sshWithOutput(string|array $commands, Closure $onOutput): SshResult;
    public function test(string $condition): bool;
    public function testConnection(): bool;

    // File transfer
    public function upload(string $localPath, string $remotePath): SshResult;
    public function download(string $remotePath, string $localPath): SshResult;

    // Rsync
    public function rsync(string $source, string $dest, array $excludes = [], array $extraFlags = []): SshResult;
    public function rsyncDryRun(string $source, string $dest, array $excludes = []): SshResult;

    // Multiplexing
    public function enableMultiplexing(): static;
    public function disableMultiplexing(): static;
    public function cleanupSockets(): void;

    // Internal
    private function buildSshOptions(): array;       // Unified SSH flags
    private function buildSshCommand(string $cmd): array|string;  // Platform-aware
    private function buildScpCommand(string $src, string $dst): array|string;
    private function buildRsyncCommand(string $src, string $dst, array $opts): array|string;
    private function expandTilde(string $path): string;  // Single implementation
    private function isWindows(): bool;
    private function windowsPathToWsl(string $path): string;
    private function runProcess(array|string $command, ?int $timeout = null, ?Closure $onOutput = null): SshResult;
}
```

```php
class SshResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
    ) {}
}
```

### Implementation Phases

#### Phase 1: SshService Core + CommandService Migration

Create `SshService` with SSH execution and multiplexing. Migrate `CommandService` away from spatie/ssh.

**Tasks:**
- [ ] Create `src/Services/SshService.php` with `ssh()`, `sshWithOutput()`, `test()`, `testConnection()`
- [ ] Create `src/Data/SshResult.php` value object
- [ ] Port SSH command building from `vendor/spatie/ssh/src/Ssh.php` (~50 lines of actual logic)
- [ ] Implement platform detection: `isWindows()`, Windows SSH binary resolution
- [ ] Implement unified `buildSshOptions()` — single source of truth for port, key, host key, multiplexing
- [ ] Implement `expandTilde()` — one implementation, not four
- [ ] Implement multiplexing with shared control socket path per `user@host:port`
- [ ] Migrate `CommandService::initializeSsh()` → `SshService::fromConfig()`
- [ ] Migrate `CommandService::remote()` → delegates to `$this->sshService->ssh()`
- [ ] Migrate `CommandService::remoteWithOutput()` → delegates to `$this->sshService->sshWithOutput()`
- [ ] Migrate `CommandService::test()` → delegates to `$this->sshService->test()`
- [ ] Migrate `ManagesDeploymentSteps::cleanupSshControlSockets()` → `$this->sshService->cleanupSockets()`
- [ ] Remove `use Spatie\Ssh\Ssh` from `CommandService`
- [ ] Write unit tests for SSH command assembly (all option combinations)
- [ ] Write unit tests for Windows command building
- [ ] Run existing test suite — everything should pass

**Files:**
- `src/Services/SshService.php` (new)
- `src/Data/SshResult.php` (new)
- `src/Services/CommandService.php` (modify)
- `src/Concerns/ManagesDeploymentSteps.php` (modify)
- `tests/Unit/SshServiceTest.php` (new)

**Success criteria:** All deployment commands (`release`, `sync`, `rollback`, `server clear`) work identically. spatie/ssh is no longer called.

#### Phase 2: SCP + Rsync + File Transfer

Add SCP and rsync to `SshService`. Migrate `RsyncService`, `DatabaseCommand`, `DiffAction`.

**Tasks:**
- [ ] Implement `upload()` and `download()` (SCP) in SshService
- [ ] Implement `rsync()` and `rsyncDryRun()` in SshService
- [ ] Windows rsync: WSL wrapper with `windowsPathToWsl()` path translation
- [ ] Windows SCP: native `scp.exe` via Process array
- [ ] Migrate `RsyncService::sync()` → delegates to `$this->sshService->rsync()`
- [ ] Migrate `RsyncService::buildRsyncCommand()` — keep filtering/exclude logic, delegate transport
- [ ] Migrate `DiffAction::calculateRemoteDiff()` → `$this->sshService->rsyncDryRun()`
- [ ] Fix `DiffAction::calculateDiff()` — replace `mktemp -d` with `sys_get_temp_dir()` + PHP `tempnam()`
- [ ] Fix `DiffAction::buildDryRunCommand()` — replace grep pipe with PHP-side filtering
- [ ] Migrate `DatabaseCommand::handleDownload()` → `$this->sshService->rsync()` (fixes port bug)
- [ ] Migrate `DatabaseCommand::executeUpload()` → `$this->sshService->upload()` (fixes port bug)
- [ ] Remove `Commands::RSYNC_SSH_OPTIONS` constant (absorbed into SshService)
- [ ] Write unit tests for rsync/SCP command building
- [ ] Write unit tests for WSL path translation

**Files:**
- `src/Services/SshService.php` (extend)
- `src/Services/RsyncService.php` (modify)
- `src/Actions/DiffAction.php` (modify)
- `src/Commands/DatabaseCommand.php` (modify)
- `src/Constants/Commands.php` (modify — remove `RSYNC_SSH_OPTIONS`)
- `tests/Unit/SshServiceTest.php` (extend)

**Success criteria:** `deployer:release`, `deployer:sync`, `deployer:db download`, `deployer:db upload` all work. Port and identity file are respected everywhere.

#### Phase 3: ServerCommand + SetupCommand Migration

Migrate the remaining independent SSH/SCP execution patterns.

**Tasks:**
- [ ] Add `SshService::fromArray()` factory for ServerCommand (uses raw config array, not DeploymentConfig)
- [ ] Migrate `ServerCommand::buildSSHCommand()` → `SshService::ssh()`
- [ ] Migrate `ServerCommand::buildSCPCommand()` → `SshService::upload()`
- [ ] Migrate `ServerCommand::executeRemoteCommand()` → `SshService::ssh()`
- [ ] Migrate `ServerCommand::executeRemoteCommandWithOutput()` → `SshService::sshWithOutput()`
- [ ] Migrate `ServerCommand::testConnection()` → `SshService::testConnection()`
- [ ] Migrate `ServerCommand::uploadScripts()` → `SshService::upload()`
- [ ] Fix `SetupCommand::copyKeyToServer()` — replace `ssh-copy-id` with manual key append via SSH (cross-platform)
- [ ] Fix `SetupCommand::generateNewKey()` — add Windows `ssh-keygen.exe` path resolution
- [ ] Add port support to SetupCommand key operations
- [ ] Write tests for ServerCommand SSH assembly
- [ ] Remove all raw `exec()`/`proc_open()` SSH calls from ServerCommand

**Files:**
- `src/Services/SshService.php` (extend — `fromArray()` factory)
- `src/Commands/ServerCommand.php` (modify)
- `src/Commands/SetupCommand.php` (modify)
- `tests/Unit/SshServiceTest.php` (extend)

**Success criteria:** `deployer:server provision`, `deployer:setup keygen`, `deployer:setup init` work on both platforms.

#### Phase 4: Cleanup + Remove spatie/ssh

Remove the dependency and clean up.

**Tasks:**
- [ ] Remove `spatie/ssh` from `composer.json`
- [ ] Remove `ServerConnection` DTO if still unused
- [ ] Remove any dead code paths left from migration
- [ ] Run full test suite
- [ ] Test across all consuming projects (per CLAUDE.md requirement)
- [ ] Dry-run deployment per consuming project: `php artisan deployer:release staging --dry-run`

**Files:**
- `composer.json` (modify)
- `src/Data/ServerConnection.php` (remove if unused)

## System-Wide Impact

### Interaction Graph

`SshService::ssh()` → builds SSH command → `Process::fromShellCommandline()` (Unix) or `Process` array (Windows) → returns `SshResult`

All callers (`CommandService`, `RsyncService`, `ServerCommand`, `DatabaseCommand`, `DiffAction`, `SetupCommand`) converge to the same execution path. Output callbacks flow through `sshWithOutput()`.

### Error Propagation

Current: 4 different exception types (`SSHConnectionException`, `TaskExecutionException`, `RsyncException`, bare `RuntimeException`). No change to exception types — `SshResult` carries exit code + output, callers throw their own domain exceptions as before.

### State Lifecycle Risks

- **Multiplexing socket files** — shared control path means cleanup must happen once, not per-service. `SshService::cleanupSockets()` is the single owner.
- **Temp files** — `DiffAction` creates temp dirs. Migration to `sys_get_temp_dir()` + PHP `tempnam()` ensures cross-platform cleanup.

### API Surface Parity

- `CommandExecutor` interface unchanged — `CommandService` still implements it
- `RsyncService` public API unchanged — callers unaffected
- `ServerCommand` internal methods change but no public API

## Acceptance Criteria

### Functional

- [ ] All deployment commands work identically on Unix (no behavioral regression)
- [ ] SSH port is respected in ALL commands (deploy, sync, db download, db upload, provision, keygen)
- [ ] Identity file is respected in ALL commands
- [ ] Strict host key checking config is respected consistently
- [ ] Multiplexing uses a single shared control socket per connection
- [ ] Windows: SSH/SCP work via native `ssh.exe`/`scp.exe`
- [ ] Windows: rsync works via WSL with proper path translation
- [ ] Windows: ControlMaster/multiplexing is skipped
- [ ] `ssh-copy-id` replaced with cross-platform alternative

### Non-Functional

- [ ] No new composer dependencies
- [ ] `spatie/ssh` removed from composer.json
- [ ] Single tilde expansion implementation
- [ ] Unit tests for all SSH/SCP/rsync command assembly
- [ ] Unit tests for Windows-specific command building
- [ ] Dry-run deployment passes in all consuming projects

## Dependencies & Prerequisites

- **PR #29 merged first** (see brainstorm: decision to respect colleague's contribution)
- No external dependencies — replacing one, adding zero

## Risk Analysis & Mitigation

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Behavioral regression in deploy | Medium | High | Phase 1 migration + full test suite + dry-run per project |
| Windows WSL not installed | Medium | Medium | Detect WSL absence, show actionable error message |
| Multiplexing socket conflict during migration | Low | Medium | New socket path format avoids collision with old |
| ServerCommand provision breaks | Medium | High | Phase 3 is last — most testing time before merge |

## Alternative Approaches Considered

1. **Just fix PR #29 gaps** — Patch Windows handling into each file individually. Rejected: perpetuates fragmentation, every new command would need its own Windows handling.

2. **Fork spatie/ssh** — Add Windows support to the existing package. Rejected: we use <10% of its API, and it adds indirection for no benefit.

3. **Layered SshService + RemoteExecutor** — More abstraction. Rejected: YAGNI. One service is sufficient for this package's scope.

## Sources & References

- **Origin brainstorm:** [docs/brainstorms/2026-03-17-INHOUSE-SSH-BRAINSTORM.md](docs/brainstorms/2026-03-17-INHOUSE-SSH-BRAINSTORM.md) — key decisions: full replacement scope, WSL for rsync + native SSH on Windows, merge PR #29 first
- PR #29: `fix/windows-compatibility` — partial Windows fixes to absorb
- spatie/ssh source: `vendor/spatie/ssh/src/Ssh.php` — ~50 lines of command building to port
- Current SSH surface: `CommandService:66-111`, `RsyncService:68-92`, `ServerCommand:689-727`, `DatabaseCommand:88-310`, `DiffAction:98-216`, `SetupCommand:980-1082`
