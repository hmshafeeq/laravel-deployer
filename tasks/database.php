<?php

namespace Deployer;

use Deployer\Exception\Exception;

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Get database configuration from Laravel and create MySQL config file
 */
function getDatabaseConfigWithFile(): array
{
    $connection = trim(run('cd {{current_path}} && php artisan tinker --execute="echo config(\'database.default\');"'));

    $config = [
        'host' => trim(run("cd {{current_path}} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.host');\"")),
        'database' => trim(run("cd {{current_path}} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.database');\"")),
        'username' => trim(run("cd {{current_path}} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.username');\"")),
        'password' => trim(run("cd {{current_path}} && php artisan tinker --execute=\"echo config('database.connections.{$connection}.password');\"")),
    ];

    if (empty($config['host']) || ! preg_match('/^[a-zA-Z0-9.-]+$/', $config['host'])) {
        throw new Exception("Invalid database host: {$config['host']}");
    }
    if (empty($config['database']) || ! preg_match('/^[a-zA-Z0-9_]+$/', $config['database'])) {
        throw new Exception("Invalid database name: {$config['database']}");
    }
    if (empty($config['username']) || ! preg_match('/^[a-zA-Z0-9_@.-]+$/', $config['username'])) {
        throw new Exception("Invalid database user: {$config['username']}");
    }
    if (empty($config['password'])) {
        throw new Exception('Database password cannot be empty');
    }

    $configFile = '/tmp/mysql_backup_' . uniqid() . '.cnf';
    run("echo '[client]' > {$configFile}");
    run("echo 'host={$config['host']}' >> {$configFile}");
    run("echo 'user={$config['username']}' >> {$configFile}");
    run("echo 'password={$config['password']}' >> {$configFile}");

    $config['config_file'] = $configFile;

    return $config;
}


/**
 * List available backups and let user select one
 */
function selectBackup(?string $selection = null): array
{
    $backupList = run('ls -lt {{deploy_path}}/shared/backups/db_backup_*.sql.gz 2>/dev/null || echo ""');
    if (empty($backupList)) {
        throw new Exception('No database backups found on server');
    }

    $backups = run('ls -lht {{deploy_path}}/shared/backups/db_backup_*.sql.gz | head -10');
    $lines = array_filter(array_map('trim', explode("\n", trim($backups))));

    // If selection argument provided, don't show interactive list
    if ($selection !== null) {
        if (strtolower($selection) === 'latest') {
            $choiceIndex = 0;
            if (isset($lines[0])) {
                $parts = preg_split('/\s+/', $lines[0]);
                $filename = basename($parts[8]);
                writeln("📋 Using latest backup: {$filename}");
            }
        } elseif (is_numeric($selection)) {
            $choiceIndex = (int) $selection - 1;
            writeln("📋 Using backup #{$selection}: " . basename($lines[$choiceIndex]));
        } else {
            throw new Exception("Invalid backup selection: {$selection}");
        }
    } else {
        // Interactive selection
        writeln('📋 Available database backups:');
        writeln('');
        foreach ($lines as $index => $line) {
            $parts = preg_split('/\s+/', $line);
            $size = $parts[4];
            $filename = basename($parts[8]);
            writeln("   " . ($index + 1) . ". {$filename} ({$size})");
        }
        writeln('');
        $choice = (int) ask('Enter backup number to download (1-' . count($lines) . ') or press Enter for latest:', '1');
        $choiceIndex = $choice - 1;
    }
    if ($choiceIndex < 0 || $choiceIndex >= count($lines)) {
        throw new Exception('Invalid backup selection');
    }

    $parts = preg_split('/\s+/', $lines[$choiceIndex]);

    return [
        'path' => $parts[8],
        'name' => basename($parts[8]),
        'size' => $parts[4],
    ];
}

/**
 * Get file size from remote server
 */
function getRemoteFileInfo(string $filePath): array
{
    $sizeBytes = (int) trim(run("stat -c%s {$filePath}"));
    $sizeHuman = trim(run("ls -lh {$filePath} | awk '{print \$5}'"));

    $fileCheck = trim(run("test -r {$filePath} && echo 'OK' || echo 'FAIL'"));
    if ($fileCheck !== 'OK') {
        throw new Exception("Cannot access backup file on remote server: {$filePath}");
    }

    return ['bytes' => $sizeBytes, 'human' => $sizeHuman];
}

/**
 * Download file with progress monitoring
 */
function downloadWithProgress(string $remoteFile, string $localFile, int $remoteSizeBytes, ?string $method = null): void
{
    $deployUser = get('remote_user');
    $deployHost = get('hostname');

    if ($method === null) {
        writeln("💡 Speed optimization tips:");
        writeln("   • Option 1 (rsync): Best for reliability, resume capability");
        writeln("   • Option 2 (scp): Often faster for large files, no resume");
        writeln("");
        $method = ask('Choose download method: (1) Optimized rsync [default] (2) Direct SCP', '1');
        writeln("");
    } else {
        $methodName = (strtolower($method) === 'scp' || $method === '2') ? 'SCP' : 'rsync';
        writeln("⚡ Using {$methodName} download method");
    }
    
    if (strtolower($method) === 'rsync' || $method === '1') {
        $method = '1';
    } elseif (strtolower($method) === 'scp' || $method === '2') {
        $method = '2';
    } else {
        $method = '1';
    }

    $startTime = microtime(true);

    if ($method === '2') {
        writeln("🚀 Using SCP for maximum speed...");
        $cmd = "scp -o Compression=no -o TCPKeepAlive=yes -o ServerAliveInterval=60 {$deployUser}@{$deployHost}:{$remoteFile} " . dirname($localFile) . "/";
    } else {
        writeln("⚡ Using optimized rsync (no compression, no bandwidth limit)...");
        $cmd = "rsync -av --partial --inplace {$deployUser}@{$deployHost}:{$remoteFile} " . dirname($localFile) . "/";
    }

    writeln("💡 This may take a while for large files. Monitoring progress every 30 seconds...");

    $logFile = '/tmp/rsync_output_' . getmypid() . '.log';
    $pid = (int) runLocally("({$cmd} > {$logFile} 2>&1 & echo \$!) | tail -1");

    if ($pid <= 0) {
        throw new Exception("Failed to start download process");
    }

    writeln("📥 Download started (PID: {$pid})");

    monitorDownloadProgress($pid, $localFile, $remoteSizeBytes);

    $logContent = runLocally("cat {$logFile} 2>/dev/null || echo 'No log available'");
    $hasError = strpos($logContent, 'rsync error:') !== false ||
        strpos($logContent, 'failed') !== false ||
        strpos($logContent, 'No such file') !== false;

    runLocally("rm -f {$logFile}");

    if ($hasError) {
        throw new Exception("Download failed. Log: {$logContent}");
    }

    $downloadTime = round(microtime(true) - $startTime, 2);
    verifyDownload($localFile, $remoteSizeBytes, $downloadTime);
}

/**
 * Monitor download progress
 */
function monitorDownloadProgress(int $pid, string $localFile, int $remoteSizeBytes): void
{
    $lastSize = 0;
    $stagnantCount = 0;
    $maxStagnantChecks = 10;

    while (true) {
        if ((int) runLocally("ps -p {$pid} > /dev/null 2>&1; echo \$?") !== 0) {
            break;
        }

        if ((int) runLocally("test -f '{$localFile}' && echo 1 || echo 0") === 1) {
            $currentSize = (int) runLocally("stat -f%z '{$localFile}' 2>/dev/null || stat -c%s '{$localFile}' 2>/dev/null || echo 0");

            if ($currentSize > 0 && $remoteSizeBytes > 0) {
                $percent = round(($currentSize / $remoteSizeBytes) * 100, 1);
                $currentMB = round($currentSize / 1024 / 1024, 1);
                $totalMB = round($remoteSizeBytes / 1024 / 1024, 1);
                $msg = "📊 Progress: {$percent}% ({$currentMB} MB / {$totalMB} MB)";

                if ($currentSize > $lastSize) {
                    $speedMBps = round(($currentSize - $lastSize) / 1024 / 1024 / 30, 2);
                    $msg .= " | Speed: {$speedMBps} MB/s";
                    $stagnantCount = 0;
                } else {
                    $stagnantCount++;
                    if ($stagnantCount >= $maxStagnantChecks) {
                        throw new Exception("Download stagnant (no progress for " . ($stagnantCount * 30) . " seconds)");
                    }
                }

                writeln($msg);
                $lastSize = $currentSize;
            }
        }

        sleep(30);
    }
}

/**
 * Verify downloaded file
 */
function verifyDownload(string $localFile, int $remoteSizeBytes, float $downloadTime): void
{
    if ((int) runLocally("test -f '{$localFile}' && echo 1 || echo 0") !== 1) {
        throw new Exception("Download failed: Local file not found");
    }

    $localSize = (int) runLocally("stat -f%z '{$localFile}' 2>/dev/null || stat -c%s '{$localFile}' 2>/dev/null || echo 0");
    $sizeDiff = abs($localSize - $remoteSizeBytes);
    $tolerance = max(1024, $remoteSizeBytes * 0.001);

    if ($sizeDiff > $tolerance) {
        throw new Exception("File size mismatch (local: {$localSize}, remote: {$remoteSizeBytes}, diff: {$sizeDiff})");
    }

    $localSizeHuman = runLocally("ls -lh '{$localFile}' | awk '{print \$5}'");
    $speedMBps = round(($localSize / 1024 / 1024) / $downloadTime, 2);

    writeln("");
    writeln("✅ Database backup downloaded successfully!");
    writeln("📁 Location: {$localFile}");
    writeln("📊 Size: {$localSizeHuman}");
    writeln("⏱️  Time: {$downloadTime}s");
    writeln("🚀 Speed: {$speedMBps} MB/s");
}

// ============================================================================
// Tasks
// ============================================================================

task('database:backup', function () {
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = "{{deploy_path}}/shared/backups/db_backup_{$timestamp}.sql.gz";

    run('mkdir -p {{deploy_path}}/shared/backups');

    $config = getDatabaseConfigWithFile();

    run("mysqldump --defaults-file={$config['config_file']} --single-transaction --routines --triggers {$config['database']} | gzip -8 > {$backupFile}");
    run("rm -f {$config['config_file']}");
    run('cd {{deploy_path}}/shared/backups && ls -t db_backup_*.sql.gz | tail -n +4 | xargs -r rm');

    $fileSize = trim(run("ls -lh {$backupFile} | awk '{print \$5}'"));
    writeln("💾 Database backed up to: {$backupFile} ({$fileSize})");
})->desc('Create database backup before deployment (structure + data)');

task('database:download', function () {
    // Get arguments from environment variables
    $backupSelection = getenv('DEPLOYER_BACKUP_SELECTION') ?: null;
    $downloadMethod = getenv('DEPLOYER_DOWNLOAD_METHOD') ?: null;
    
    $backup = selectBackup($backupSelection);
    $remoteInfo = getRemoteFileInfo($backup['path']);

    runLocally('mkdir -p ./backups');

    writeln("📥 Downloading {$backup['name']} ({$remoteInfo['human']})...");

    try {
        downloadWithProgress($backup['path'], "./backups/{$backup['name']}", $remoteInfo['bytes'], $downloadMethod);
        writeln('');
        writeln('💡 To restore locally: php artisan database:restore ' . $backup['name']);
    } catch (Exception $e) {
        $localFile = "./backups/{$backup['name']}";
        if ((int) runLocally("test -f '{$localFile}' && echo 1 || echo 0") === 1) {
            $localSize = (int) runLocally("stat -f%z '{$localFile}' 2>/dev/null || stat -c%s '{$localFile}' 2>/dev/null || echo 0");
            if ($localSize < ($remoteInfo['bytes'] * 0.95)) {
                runLocally("rm -f '{$localFile}'");
                writeln("🧹 Cleaned up partial download");
            } else {
                writeln("📁 File appears complete, keeping download");
            }
        }
        throw new Exception("Download failed: " . $e->getMessage());
    }
})->desc('Download database backup from server to local machine');