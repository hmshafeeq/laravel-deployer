<?php

use Shaf\LaravelDeployer\Actions\DeployAction;
use Shaf\LaravelDeployer\Actions\DiffAction;
use Shaf\LaravelDeployer\Actions\HealthCheckAction;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\SyncDiff;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\DeploymentService;
use Shaf\LaravelDeployer\Services\ReceiptService;
use Shaf\LaravelDeployer\Services\RsyncService;

beforeEach(function () {
    $this->config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www/app',
        composerOptions: '--prefer-dist'
    );

    // Mock all services
    $this->deployment = Mockery::mock(DeploymentService::class);
    $this->cmd = Mockery::mock(CommandService::class);
    $this->rsync = Mockery::mock(RsyncService::class);
    $this->diff = Mockery::mock(DiffAction::class);
    $this->healthCheck = Mockery::mock(HealthCheckAction::class);
    $this->receipt = Mockery::mock(ReceiptService::class);

    // Common expectations
    $this->cmd->shouldReceive('task')->byDefault();
    $this->cmd->shouldReceive('info')->byDefault();
    $this->cmd->shouldReceive('success')->byDefault();
    $this->cmd->shouldReceive('debug')->byDefault();
    $this->cmd->shouldReceive('warning')->byDefault();
    $this->cmd->shouldReceive('getOutput')->byDefault();
    $this->cmd->shouldReceive('newLine')->byDefault(); // Used by summary

    // DeployAction instance
    $this->action = new DeployAction(
        $this->deployment,
        $this->cmd,
        $this->rsync,
        $this->diff,
        $this->config,
        $this->healthCheck,
        $this->receipt
    );
});

afterEach(function () {
    Mockery::close();
});

test('execute() runs complete happy path', function () {
    // 1. Lock
    $this->deployment->shouldReceive('isLocked')->once()->andReturn(false);
    $this->deployment->shouldReceive('lock')->once();

    // 2. Setup (includes mkdir, touch, and setgid enforcement)
    $this->cmd->shouldReceive('runBatch')->with(Mockery::on(function ($commands) {
        return is_array($commands)
            && str_contains($commands[0], 'mkdir -p')
            && str_contains($commands[1], 'touch');
    }))->once();

    // 3. Create release
    $this->deployment->shouldReceive('generateReleaseName')->once()->andReturn('202501.1');
    $this->deployment->shouldReceive('setCurrentReleaseName')->with('202501.1')->once();
    $this->deployment->shouldReceive('getReleasePath')->with('202501.1')->andReturn('/var/www/app/releases/202501.1');

    // 4. Build assets (skipped for non-local by default in test unless we mock otherwise, but here we assume remote)
    $this->cmd->shouldReceive('local')->with('npm run build')->once();

    // 5. Diff
    $this->deployment->shouldReceive('getCurrentPath')->andReturn('/var/www/app/current');
    $this->cmd->shouldReceive('symlinkExists')->andReturn(true);
    $this->diff->shouldReceive('showRemoteDiff')->once()->andReturn(new SyncDiff);
    $this->diff->shouldReceive('confirmChanges')->once()->andReturn(true);
    $this->diff->shouldReceive('showUploadProgress')->byDefault();
    $this->diff->shouldReceive('showUploadComplete')->byDefault();

    // 6. Sync
    $this->deployment->shouldReceive('getCurrentRelease')->andReturn(null); // No prev release for hardlink
    $this->rsync->shouldReceive('setSyncDiff')->once()->andReturnSelf();
    $this->rsync->shouldReceive('setOutput')->once()->andReturnSelf();
    $this->rsync->shouldReceive('sync')->with('/var/www/app/releases/202501.1')->once();
    $this->rsync->shouldReceive('getFilesSynced')->andReturn(10);
    $this->rsync->shouldReceive('getTotalBytesTransferred')->andReturn(1024);

    // 7. Shared links
    $this->deployment->shouldReceive('getSharedPath')->andReturn('/var/www/app/shared');
    $this->cmd->shouldReceive('runBatch')->byDefault(); // Allow multiple runBatch calls

    // 8. Fix permissions & Composer
    $this->cmd->shouldReceive('remote')->byDefault(); // Allow multiple remote calls for cleanup, etc.
    $this->cmd->shouldReceive('fileExists')->byDefault()->andReturn(true);
    $this->cmd->shouldReceive('remoteWithOutput')->byDefault();
    $this->cmd->shouldReceive('directoryExists')->byDefault()->andReturn(false);

    // 9. Permissions & Migrations
    $this->cmd->shouldReceive('artisanMigrate')->with(Mockery::any(), true)->once()->andReturn(['count' => 0]);

    // 10. Link release (.dep and current)
    $this->deployment->shouldReceive('getCurrentPath')->andReturn('/var/www/app/current');

    // 11. Health Check
    $this->healthCheck->shouldReceive('verifyDeployment')->once();

    // 13. Log & Receipt
    $this->deployment->shouldReceive('getUser')->andReturn('deployer');
    $this->cmd->shouldReceive('remote')->with(Mockery::pattern('/echo .*deploy.log/'))->once();
    $this->deployment->shouldReceive('logRelease')->once();
    $this->receipt->shouldReceive('save')->once();

    // 14. Unlock (finally block)
    $this->deployment->shouldReceive('unlock')->once();

    $this->action->execute();
});

test('execute() unlocks deployment on failure', function () {
    // 1. Lock succeeds
    $this->deployment->shouldReceive('isLocked')->andReturn(false);
    $this->deployment->shouldReceive('lock')->once();

    // 2. Setup fails (includes mkdir, touch, and setgid enforcement)
    $this->cmd->shouldReceive('runBatch')
        ->with(Mockery::on(fn ($commands) => is_array($commands) && str_contains($commands[0], 'mkdir -p')))
        ->andThrow(new Exception('SSH Error'));

    // Expect unlock to be called
    $this->deployment->shouldReceive('unlock')->once();

    expect(fn () => $this->action->execute())->toThrow(Exception::class, 'SSH Error');
});

test('execute() skips diff confirmation when configured', function () {
    // Re-init with confirmChanges = false
    $config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'host',
        remoteUser: 'user',
        deployPath: '/path',
        composerOptions: '',
        confirmChanges: false // Key config
    );

    $action = new DeployAction(
        $this->deployment,
        $this->cmd,
        $this->rsync,
        $this->diff,
        $config,
        null,
        null
    );

    // Setup partial mock flow to reach diff step
    $this->deployment->shouldReceive('isLocked', 'lock', 'generateReleaseName', 'setCurrentReleaseName', 'getReleasePath')->byDefault();
    $this->cmd->shouldReceive('runBatch', 'local', 'symlinkExists')->byDefault();
    $this->deployment->shouldReceive('getCurrentPath')->andReturn('/path/current');

    // Diff should be shown but NOT confirmed
    // Note: The code will call show() if no current symlink exists, showRemoteDiff() otherwise
    $this->diff->shouldReceive('showRemoteDiff')->byDefault()->andReturn(new SyncDiff);
    $this->diff->shouldReceive('show')->byDefault()->andReturn(new SyncDiff);
    $this->diff->shouldReceive('showUploadProgress')->byDefault();
    $this->diff->shouldReceive('showUploadComplete')->byDefault();
    $this->diff->shouldReceive('confirmChanges')->never();

    // Mock getCurrentRelease for copyPreviousRelease
    $this->deployment->shouldReceive('getCurrentRelease')->byDefault()->andReturn(null);
    $this->cmd->shouldReceive('directoryExists')->byDefault()->andReturn(false);

    // Fail early to stop test
    $this->rsync->shouldReceive('setSyncDiff')->andThrow(new Exception('Stop test'));
    $this->deployment->shouldReceive('unlock')->byDefault();

    expect(fn () => $action->execute())->toThrow(Exception::class, 'Stop test');
});

test('executeSyncOnly() runs sync-only deployment flow', function () {
    $releaseName = '202501.5';
    $releasePath = '/var/www/app/releases/202501.5';

    // 1. Lock
    $this->deployment->shouldReceive('isLocked')->once()->andReturn(false);
    $this->deployment->shouldReceive('lock')->once();

    // 2. No asset build for this test (skipAssetBuild = true)

    // 3. Diff (symlinkExists for /current)
    $this->cmd->shouldReceive('symlinkExists')->with('/var/www/app/current')->andReturn(true);
    $this->diff->shouldReceive('showRemoteDiff')->once()->andReturn(new SyncDiff);

    // 4. Sync files (no copy from previous release in sync-only)
    $this->rsync->shouldReceive('setSyncDiff')->once()->andReturnSelf();
    $this->rsync->shouldReceive('setOutput')->once()->andReturnSelf();
    $this->rsync->shouldReceive('sync')->with($releasePath)->once();
    $this->rsync->shouldReceive('getFilesSynced')->andReturn(5);
    $this->rsync->shouldReceive('getTotalBytesTransferred')->andReturn(512);

    // 5. Ensure storage structure (symlink exists = skip fix)
    $this->cmd->shouldReceive('symlinkExists')->with("{$releasePath}/storage")->andReturn(true);

    // 6. Clear caches (sync-only specific)
    $this->cmd->shouldReceive('remote')->with(Mockery::pattern('/rm -rf.*bootstrap\/cache.*optimize:clear/'))->once();

    // 7. Composer install (sync-only uses --no-scripts, then runs package:discover)
    $this->cmd->shouldReceive('remoteWithOutput')->with(Mockery::pattern('/composer install.*--no-scripts/'))->once();

    // 8. Fix permissions
    $this->cmd->shouldReceive('runBatch')->byDefault();

    // 9. Migrations
    $this->cmd->shouldReceive('artisanMigrate')->with($releasePath, true)->once()->andReturn(['count' => 0]);

    // 10. beforeSymlink commands (none configured)

    // 11. Storage link (public/storage symlink exists = skip artisan storage:link)
    $this->cmd->shouldReceive('symlinkExists')->with("{$releasePath}/public/storage")->andReturn(true);

    // 12. Unlock (finally)
    $this->deployment->shouldReceive('unlock')->once();

    // Allow other remote calls
    $this->cmd->shouldReceive('remote')->byDefault();
    $this->cmd->shouldReceive('fileExists')->byDefault()->andReturn(false);

    $this->action->executeSyncOnly($releaseName, $releasePath, skipAssetBuild: true);

    expect($this->action->getReleaseName())->toBe($releaseName);
});

test('executeSyncOnly() does not create new release', function () {
    $releaseName = '202501.5';
    $releasePath = '/var/www/app/releases/202501.5';

    // Lock
    $this->deployment->shouldReceive('isLocked')->andReturn(false);
    $this->deployment->shouldReceive('lock')->once();
    $this->deployment->shouldReceive('unlock')->once();

    // generateReleaseName should NEVER be called in sync-only mode
    $this->deployment->shouldReceive('generateReleaseName')->never();
    $this->deployment->shouldReceive('setCurrentReleaseName')->never();

    // Allow other operations to fail early
    $this->cmd->shouldReceive('symlinkExists')->andReturn(false);
    $this->rsync->shouldReceive('setSyncDiff')->andThrow(new Exception('Stop test'));

    expect(fn () => $this->action->executeSyncOnly($releaseName, $releasePath, true))
        ->toThrow(Exception::class, 'Stop test');
});

test('executeSyncOnly() unlocks deployment on failure', function () {
    $releaseName = '202501.5';
    $releasePath = '/var/www/app/releases/202501.5';

    // Lock succeeds
    $this->deployment->shouldReceive('isLocked')->andReturn(false);
    $this->deployment->shouldReceive('lock')->once();

    // Fail during diff
    $this->cmd->shouldReceive('symlinkExists')->andThrow(new Exception('SSH Error'));

    // Unlock must be called
    $this->deployment->shouldReceive('unlock')->once();

    expect(fn () => $this->action->executeSyncOnly($releaseName, $releasePath, true))
        ->toThrow(Exception::class, 'SSH Error');
});

test('executeSyncOnly() uses --no-scripts for composer install', function () {
    $releaseName = '202501.5';
    $releasePath = '/var/www/app/releases/202501.5';

    // Setup mocks to reach composer install step
    $this->deployment->shouldReceive('isLocked')->andReturn(false);
    $this->deployment->shouldReceive('lock')->once();
    $this->deployment->shouldReceive('unlock')->once();

    // Diff check (symlinkExists for /current)
    $this->cmd->shouldReceive('symlinkExists')->with('/var/www/app/current')->andReturn(true);
    $this->diff->shouldReceive('showRemoteDiff')->andReturn(new SyncDiff);

    $this->rsync->shouldReceive('setSyncDiff')->andReturnSelf();
    $this->rsync->shouldReceive('setOutput')->andReturnSelf();
    $this->rsync->shouldReceive('sync')->once();
    $this->rsync->shouldReceive('getFilesSynced')->andReturn(1);
    $this->rsync->shouldReceive('getTotalBytesTransferred')->andReturn(100);

    // Ensure storage structure (symlink exists = skip fix)
    $this->cmd->shouldReceive('symlinkExists')->with("{$releasePath}/storage")->andReturn(true);

    // Cache clearing
    $this->cmd->shouldReceive('remote')->with(Mockery::pattern('/optimize:clear/'))->once();

    // Composer install MUST have --no-scripts (no-plugins removed to allow autoloader plugins)
    $this->cmd->shouldReceive('remoteWithOutput')
        ->with(Mockery::on(function ($cmd) {
            return str_contains($cmd, 'composer install')
                && str_contains($cmd, '--no-scripts');
        }))
        ->once();

    // package:discover runs after composer install
    $this->cmd->shouldReceive('remote')->with(Mockery::pattern('/package:discover/'))->once();

    // Fail after composer to stop test (during permissions fix runBatch)
    $this->cmd->shouldReceive('runBatch')->andThrow(new Exception('Stop test'));

    expect(fn () => $this->action->executeSyncOnly($releaseName, $releasePath, true))
        ->toThrow(Exception::class, 'Stop test');
});
