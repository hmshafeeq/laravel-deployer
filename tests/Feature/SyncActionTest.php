<?php

use Shaf\LaravelDeployer\Actions\DiffAction;
use Shaf\LaravelDeployer\Actions\SyncAction;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\SyncDiff;
use Shaf\LaravelDeployer\Data\SyncFileCategories;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Services\CommandService;
use Shaf\LaravelDeployer\Services\DeploymentService;
use Shaf\LaravelDeployer\Services\RsyncService;

beforeEach(function () {
    $this->config = new DeploymentConfig(
        environment: Environment::PRODUCTION,
        hostname: 'example.com',
        remoteUser: 'deploy',
        deployPath: '/var/www/app',
        composerOptions: '--prefer-dist'
    );

    $this->deployment = Mockery::mock(DeploymentService::class);
    $this->cmd = Mockery::mock(CommandService::class);
    $this->rsync = Mockery::mock(RsyncService::class);
    $this->diff = Mockery::mock(DiffAction::class);

    $this->cmd->shouldReceive('task')->byDefault();
    $this->cmd->shouldReceive('info')->byDefault();
    $this->cmd->shouldReceive('success')->byDefault();
    $this->cmd->shouldReceive('debug')->byDefault();
    $this->cmd->shouldReceive('warning')->byDefault();
    $this->cmd->shouldReceive('getOutput')->byDefault();
    $this->cmd->shouldReceive('newLine')->byDefault();

    $this->action = new SyncAction(
        $this->deployment,
        $this->cmd,
        $this->rsync,
        $this->diff,
        $this->config,
    );
});

afterEach(function () {
    Mockery::close();
});

test('execute() runs sync-only deployment flow', function () {
    $releaseName = '202501.5';
    $releasePath = '/var/www/app/releases/202501.5';

    // 1. Lock
    $this->deployment->shouldReceive('isLocked')->once()->andReturn(false);
    $this->deployment->shouldReceive('lock')->once();

    // 2. Diff
    $this->cmd->shouldReceive('symlinkExists')->with('/var/www/app/current')->andReturn(true);
    $this->diff->shouldReceive('showRemoteDiff')->once()->andReturn(new SyncDiff);

    // 3. Sync files
    $this->rsync->shouldReceive('setSyncDiff')->once()->andReturnSelf();
    $this->rsync->shouldReceive('setOutput')->once()->andReturnSelf();
    $this->rsync->shouldReceive('sync')->with($releasePath, null)->once();
    $this->rsync->shouldReceive('getFilesSynced')->andReturn(5);
    $this->rsync->shouldReceive('getTotalBytesTransferred')->andReturn(512);

    // 4. Ensure storage structure
    $this->cmd->shouldReceive('symlinkExists')->with("{$releasePath}/storage")->andReturn(true);

    // 5. Clear caches
    $this->cmd->shouldReceive('remote')->with(Mockery::pattern('/rm -rf.*bootstrap\/cache.*optimize:clear/'))->once();

    // 6. Composer install
    $this->cmd->shouldReceive('remoteWithOutput')->with(Mockery::pattern('/composer install.*--no-scripts/'))->once();

    // 7. Fix permissions
    $this->cmd->shouldReceive('runBatch')->byDefault();

    // 8. Migrations
    $this->cmd->shouldReceive('artisanMigrate')->with($releasePath, true)->once()->andReturn(['count' => 0]);

    // 9. Storage link
    $this->cmd->shouldReceive('symlinkExists')->with("{$releasePath}/public/storage")->andReturn(true);

    // 10. Unlock
    $this->deployment->shouldReceive('unlock')->once();

    // Allow other remote calls
    $this->cmd->shouldReceive('remote')->byDefault();
    $this->cmd->shouldReceive('fileExists')->byDefault()->andReturn(false);

    $this->action->execute($releaseName, $releasePath, skipAssetBuild: true);

    expect($this->action->getReleaseName())->toBe($releaseName);
});

test('execute() does not create new release', function () {
    $releaseName = '202501.5';
    $releasePath = '/var/www/app/releases/202501.5';

    $this->deployment->shouldReceive('isLocked')->andReturn(false);
    $this->deployment->shouldReceive('lock')->once();
    $this->deployment->shouldReceive('unlock')->once();

    // generateReleaseName should NEVER be called in sync-only mode
    $this->deployment->shouldReceive('generateReleaseName')->never();
    $this->deployment->shouldReceive('setCurrentReleaseName')->never();

    // Allow other operations to fail early
    $this->cmd->shouldReceive('symlinkExists')->andReturn(false);
    $this->rsync->shouldReceive('setSyncDiff')->andThrow(new Exception('Stop test'));

    expect(fn () => $this->action->execute($releaseName, $releasePath, true))
        ->toThrow(Exception::class, 'Stop test');
});

test('execute() unlocks deployment on failure', function () {
    $releaseName = '202501.5';
    $releasePath = '/var/www/app/releases/202501.5';

    $this->deployment->shouldReceive('isLocked')->andReturn(false);
    $this->deployment->shouldReceive('lock')->once();

    // Fail during diff
    $this->cmd->shouldReceive('symlinkExists')->andThrow(new Exception('SSH Error'));

    // Unlock must be called
    $this->deployment->shouldReceive('unlock')->once();

    expect(fn () => $this->action->execute($releaseName, $releasePath, true))
        ->toThrow(Exception::class, 'SSH Error');
});

test('execute() skips steps based on file categories', function () {
    $releaseName = '202501.5';
    $releasePath = '/var/www/app/releases/202501.5';

    // Categories with no composer.lock, no migrations, no new files
    $categories = new SyncFileCategories(
        hasComposerLock: false,
        hasFrontendAssets: false,
        hasMigrations: false,
        hasNewFiles: false,
    );

    $this->deployment->shouldReceive('isLocked')->andReturn(false);
    $this->deployment->shouldReceive('lock')->once();
    $this->deployment->shouldReceive('unlock')->once();

    $this->cmd->shouldReceive('symlinkExists')->with('/var/www/app/current')->andReturn(true);
    $this->diff->shouldReceive('showRemoteDiff')->andReturn(new SyncDiff);

    $this->rsync->shouldReceive('setSyncDiff')->andReturnSelf();
    $this->rsync->shouldReceive('setOutput')->andReturnSelf();
    $this->rsync->shouldReceive('sync')->once();
    $this->rsync->shouldReceive('getFilesSynced')->andReturn(1);
    $this->rsync->shouldReceive('getTotalBytesTransferred')->andReturn(100);

    $this->cmd->shouldReceive('symlinkExists')->with("{$releasePath}/storage")->andReturn(true);
    $this->cmd->shouldReceive('remote')->byDefault();
    $this->cmd->shouldReceive('remoteWithOutput')->byDefault();
    $this->cmd->shouldReceive('runBatch')->byDefault();
    $this->cmd->shouldReceive('fileExists')->byDefault()->andReturn(false);
    $this->cmd->shouldReceive('symlinkExists')->with("{$releasePath}/public/storage")->andReturn(true);

    // Composer, permissions, and migrations should NOT be called
    $this->cmd->shouldReceive('artisanMigrate')->never();

    $this->action->execute(
        $releaseName,
        $releasePath,
        skipAssetBuild: true,
        categories: $categories,
    );

    $skipped = $this->action->getSkippedSteps();
    expect($skipped)->toContain('composer:install');
    expect($skipped)->toContain('permissions:fix');
    expect($skipped)->toContain('artisan:migrate');
    expect($skipped)->toContain('assets:build');
});

test('execute() passes filesFromPath to rsync', function () {
    $releaseName = '202501.5';
    $releasePath = '/var/www/app/releases/202501.5';
    $filesFromPath = '/tmp/deployer-files-from-test';

    $this->deployment->shouldReceive('isLocked')->andReturn(false);
    $this->deployment->shouldReceive('lock')->once();
    $this->deployment->shouldReceive('unlock')->once();

    $this->cmd->shouldReceive('symlinkExists')->with('/var/www/app/current')->andReturn(true);
    $this->diff->shouldReceive('showRemoteDiff')->andReturn(new SyncDiff);

    // Rsync should be called with filesFromPath
    $this->rsync->shouldReceive('setSyncDiff')->andReturnSelf();
    $this->rsync->shouldReceive('setOutput')->andReturnSelf();
    $this->rsync->shouldReceive('sync')->with($releasePath, $filesFromPath)->once();
    $this->rsync->shouldReceive('getFilesSynced')->andReturn(3);
    $this->rsync->shouldReceive('getTotalBytesTransferred')->andReturn(200);

    // Fail after sync to stop test
    $this->cmd->shouldReceive('symlinkExists')->with("{$releasePath}/storage")->andThrow(new Exception('Stop test'));

    expect(fn () => $this->action->execute($releaseName, $releasePath, true, $filesFromPath))
        ->toThrow(Exception::class, 'Stop test');
});
