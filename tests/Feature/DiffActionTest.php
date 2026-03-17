<?php

use Shaf\LaravelDeployer\Actions\DiffAction;
use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\SyncDiff;
use Shaf\LaravelDeployer\Enums\Environment;
use Shaf\LaravelDeployer\Services\CommandService;

// Create a test subclass that overrides the private calculateDiff method
class TestableDiffAction extends DiffAction
{
    private SyncDiff $testDiff;

    public function setTestDiff(SyncDiff $diff): void
    {
        $this->testDiff = $diff;
    }

    protected function calculateDiff(): SyncDiff
    {
        return $this->testDiff;
    }
}

beforeEach(function () {
    $this->config = new DeploymentConfig(
        environment: Environment::LOCAL,
        hostname: 'localhost',
        remoteUser: 'deploy',
        deployPath: '/var/www/test',
        composerOptions: '--prefer-dist'
    );

    $this->cmd = Mockery::mock(CommandService::class);
    $this->sourcePath = '/tmp/test-source';

    $this->action = new TestableDiffAction($this->cmd, $this->config, $this->sourcePath);
});

afterEach(function () {
    Mockery::close();
});

test('calculate() returns SyncDiff object', function () {
    // Use Mockery to mock the Process class static method
    $mockProcess = Mockery::mock('overload:Symfony\Component\Process\Process');
    $mockProcess->shouldReceive('fromShellCommandline')
        ->andReturnSelf();
    $mockProcess->shouldReceive('setTimeout')
        ->with(300)
        ->andReturnSelf();
    $mockProcess->shouldReceive('run')->andReturnSelf();
    $mockProcess->shouldReceive('getOutput')
        ->andReturn("deleting file1.txt\ndeleting file2.txt\n<f+++++++++ newfile.php\n<f.st..... modified.php");

    $this->cmd->shouldReceive('debug')
        ->with('Calculating sync differences...')
        ->once();
    // temp dir now created via PHP mkdir() — no local() call needed
    $this->cmd->shouldReceive('debug')
        ->with(Mockery::pattern('/Dry-run command:/'))
        ->once();
    $this->cmd->shouldReceive('debug')
        ->with('Parsing rsync output...')
        ->once();
    // cleanup now via PHP rmdir() — no local() call needed

    $result = $this->action->calculate();

    expect($result)->toBeInstanceOf(SyncDiff::class);
    expect($result->deletedFiles)->toContain('file1.txt');
    expect($result->deletedFiles)->toContain('file2.txt');
    expect($result->newFiles)->toContain('newfile.php');
    expect($result->modifiedFiles)->toContain('modified.php');
});

test('calculate() populates newFiles from rsync dry-run', function () {
    $mockProcess = Mockery::mock('overload:Symfony\Component\Process\Process');
    $mockProcess->shouldReceive('fromShellCommandline')
        ->andReturnSelf();
    $mockProcess->shouldReceive('setTimeout')->andReturnSelf();
    $mockProcess->shouldReceive('run')->andReturnSelf();
    $mockProcess->shouldReceive('getOutput')
        ->andReturn("<f+++++++++ app.php\n<f+++++++++ config.php");

    $this->cmd->shouldReceive('debug')->once();
    // temp dir now created via PHP mkdir()
    $this->cmd->shouldReceive('debug')->twice(); // command and parsing debug
    // cleanup now via PHP rmdir()

    $result = $this->action->calculate();

    expect($result->newFiles)->toBe(['app.php', 'config.php']);
    expect($result->deletedFiles)->toBeEmpty();
    expect($result->modifiedFiles)->toBeEmpty();
});

test('calculate() populates modifiedFiles from rsync dry-run', function () {
    $mockProcess = Mockery::mock('overload:Symfony\Component\Process\Process');
    $mockProcess->shouldReceive('fromShellCommandline')
        ->andReturnSelf();
    $mockProcess->shouldReceive('setTimeout')->andReturnSelf();
    $mockProcess->shouldReceive('run')->andReturnSelf();
    $mockProcess->shouldReceive('getOutput')
        ->andReturn("<f.st..... routes.php\n<f..t..... controller.php");

    $this->cmd->shouldReceive('debug')->once();
    // temp dir now created via PHP mkdir()
    $this->cmd->shouldReceive('debug')->twice();
    // cleanup now via PHP rmdir()

    $result = $this->action->calculate();

    expect($result->modifiedFiles)->toBe(['routes.php', 'controller.php']);
    expect($result->newFiles)->toBeEmpty();
    expect($result->deletedFiles)->toBeEmpty();
});

test('calculate() populates deletedFiles from rsync dry-run', function () {
    $mockProcess = Mockery::mock('overload:Symfony\Component\Process\Process');
    $mockProcess->shouldReceive('fromShellCommandline')
        ->andReturnSelf();
    $mockProcess->shouldReceive('setTimeout')->andReturnSelf();
    $mockProcess->shouldReceive('run')->andReturnSelf();
    $mockProcess->shouldReceive('getOutput')
        ->andReturn("deleting old-cache.txt\ndeleting temp.log");

    $this->cmd->shouldReceive('debug')->once();
    // temp dir now created via PHP mkdir()
    $this->cmd->shouldReceive('debug')->twice();
    // cleanup now via PHP rmdir()

    $result = $this->action->calculate();

    expect($result->deletedFiles)->toBe(['old-cache.txt', 'temp.log']);
    expect($result->newFiles)->toBeEmpty();
    expect($result->modifiedFiles)->toBeEmpty();
});

test('show() displays diff summary to output', function () {
    $mockProcess = Mockery::mock('overload:Symfony\Component\Process\Process');
    $mockProcess->shouldReceive('fromShellCommandline')
        ->andReturnSelf();
    $mockProcess->shouldReceive('setTimeout')->andReturnSelf();
    $mockProcess->shouldReceive('run')->andReturnSelf();
    $mockProcess->shouldReceive('getOutput')
        ->andReturn("<f+++++++++ new.php\n<f.st..... modified.php\ndeleting deleted.php");

    $this->cmd->shouldReceive('debug')->once();
    // temp dir now created via PHP mkdir()
    $this->cmd->shouldReceive('debug')->twice();
    // cleanup now via PHP rmdir()

    $this->cmd->shouldReceive('section')
        ->with('SYNC DIFFERENCE - FILES TO DEPLOY')
        ->once();
    $this->cmd->shouldReceive('info')
        ->with('  Total changes: <fg=yellow>3</> file(s)')
        ->once();
    $this->cmd->shouldReceive('newLine')
        ->times(10); // 4 initial + 3 per file list (new/modified/deleted) + 3 final
    $this->cmd->shouldReceive('write')
        ->with('  <fg=green>● New files (1):</>')
        ->once();
    $this->cmd->shouldReceive('write')
        ->with('    <fg=green>+</> new.php')
        ->once();
    $this->cmd->shouldReceive('write')
        ->with('  <fg=yellow>● Modified files (1):</>')
        ->once();
    $this->cmd->shouldReceive('write')
        ->with('    <fg=yellow>~</> modified.php')
        ->once();
    $this->cmd->shouldReceive('write')
        ->with('  <fg=red>● Deleted files (1):</>')
        ->once();
    $this->cmd->shouldReceive('write')
        ->with('    <fg=red>-</> deleted.php')
        ->once();

    $result = $this->action->show();

    expect($result)->toBeInstanceOf(SyncDiff::class);
});

test('confirmChanges() returns true when user confirms', function () {
    $diff = new SyncDiff(
        newFiles: ['new.php'],
        modifiedFiles: [],
        deletedFiles: []
    );

    $this->cmd->shouldReceive('newLine')->once();
    $this->cmd->shouldReceive('confirm')
        ->with('Do you want to proceed with uploading these changes?', true)
        ->andReturn(true);

    $result = $this->action->confirmChanges($diff);

    expect($result)->toBeTrue();
});

test('confirmChanges() returns false when user declines', function () {
    $diff = new SyncDiff(
        newFiles: ['new.php'],
        modifiedFiles: [],
        deletedFiles: []
    );

    $this->cmd->shouldReceive('newLine')->once();
    $this->cmd->shouldReceive('confirm')
        ->with('Do you want to proceed with uploading these changes?', true)
        ->andReturn(false);

    $result = $this->action->confirmChanges($diff);

    expect($result)->toBeFalse();
});
