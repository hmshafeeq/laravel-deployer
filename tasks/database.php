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

    $configFile = '/tmp/mysql_backup_'.uniqid().'.cnf';
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
            writeln("📋 Using backup #{$selection}: ".basename($lines[$choiceIndex]));
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
            writeln('   '.($index + 1).". {$filename} ({$size})");
        }
        writeln('');
        $choice = (int) ask('Enter backup number to download (1-'.count($lines).') or press Enter for latest:', '1');
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
        writeln('💡 Speed optimization tips:');
        writeln('   • Option 1 (rsync): Best for reliability, resume capability');
        writeln('   • Option 2 (scp): Often faster for large files, no resume');
        writeln('');
        $method = ask('Choose download method: (1) Optimized rsync [default] (2) Direct SCP', '1');
        writeln('');
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
        writeln('🚀 Using SCP for maximum speed...');
        $cmd = "scp -o Compression=no -o TCPKeepAlive=yes -o ServerAliveInterval=60 {$deployUser}@{$deployHost}:{$remoteFile} ".dirname($localFile).'/';
    } else {
        writeln('⚡ Using optimized rsync (no compression, no bandwidth limit)...');
        $cmd = "rsync -av --partial --inplace {$deployUser}@{$deployHost}:{$remoteFile} ".dirname($localFile).'/';
    }

    writeln('💡 This may take a while for large files. Monitoring progress every 30 seconds...');

    $logFile = '/tmp/rsync_output_'.getmypid().'.log';
    $pid = (int) runLocally("({$cmd} > {$logFile} 2>&1 & echo \$!) | tail -1");

    if ($pid <= 0) {
        throw new Exception('Failed to start download process');
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
                        throw new Exception('Download stagnant (no progress for '.($stagnantCount * 30).' seconds)');
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
        throw new Exception('Download failed: Local file not found');
    }

    $localSize = (int) runLocally("stat -f%z '{$localFile}' 2>/dev/null || stat -c%s '{$localFile}' 2>/dev/null || echo 0");
    $sizeDiff = abs($localSize - $remoteSizeBytes);
    $tolerance = max(1024, $remoteSizeBytes * 0.001);

    if ($sizeDiff > $tolerance) {
        throw new Exception("File size mismatch (local: {$localSize}, remote: {$remoteSizeBytes}, diff: {$sizeDiff})");
    }

    $localSizeHuman = runLocally("ls -lh '{$localFile}' | awk '{print \$5}'");
    $speedMBps = round(($localSize / 1024 / 1024) / $downloadTime, 2);

    writeln('');
    writeln('✅ Database backup downloaded successfully!');
    writeln("📁 Location: {$localFile}");
    writeln("📊 Size: {$localSizeHuman}");
    writeln("⏱️  Time: {$downloadTime}s");
    writeln("🚀 Speed: {$speedMBps} MB/s");
}

/**
 * Upload file with progress monitoring
 */
function uploadWithProgress(string $localFile, string $remoteDestination, string $sshKey, int $localSizeBytes): void
{
    $startTime = microtime(true);

    // Build rsync command with progress output
    $rsyncCmd = sprintf(
        'rsync -avz --progress -e "ssh -i %s" %s %s',
        escapeshellarg($sshKey),
        escapeshellarg($localFile),
        escapeshellarg($remoteDestination)
    );

    writeln('🚀 Starting upload with rsync...');
    writeln('');

    // Run rsync directly (blocking) - rsync will show its own progress
    try {
        $result = runLocally($rsyncCmd);

        $uploadTime = round(microtime(true) - $startTime, 2);
        $speedMBps = round(($localSizeBytes / 1024 / 1024) / $uploadTime, 2);

        writeln('');
        writeln('✅ Database backup uploaded successfully!');
        writeln("📊 Size: ".round($localSizeBytes / 1024 / 1024, 2).' MB');
        writeln("⏱️  Time: {$uploadTime}s");
        writeln("🚀 Speed: {$speedMBps} MB/s");
    } catch (\Exception $e) {
        throw new Exception("Upload failed: ".$e->getMessage());
    }
}

// ============================================================================
// Tasks
// ============================================================================

task('database:backup', function () {
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = "{{deploy_path}}/shared/backups/db_backup_{$timestamp}.sql.gz";

    run('mkdir -p {{deploy_path}}/shared/backups');

    writeln('🔍 Getting database configuration...');
    $config = getDatabaseConfigWithFile();

    try {
        writeln('💾 Starting database backup...');
        writeln("📊 Database: {$config['database']}");
        writeln("🏠 Host: {$config['host']}");
        writeln('');
        writeln('⏳ This may take a while for large databases...');

        // Run mysqldump with timeout and proper error handling
        $dumpCommand = "timeout 1800 mysqldump --defaults-file={$config['config_file']} --single-transaction --routines --triggers {$config['database']} 2>&1";
        $compressCommand = "gzip -8 > {$backupFile}";

        $result = run("{$dumpCommand} | {$compressCommand}; echo \$?");
        $exitCode = (int) trim($result);

        if ($exitCode !== 0) {
            throw new Exception("mysqldump failed with exit code: {$exitCode}");
        }

        // Verify backup file was created and has content
        $fileExists = trim(run("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
        if ($fileExists !== 'OK') {
            throw new Exception("Backup file was not created: {$backupFile}");
        }

        $fileSize = (int) trim(run("stat -c%s {$backupFile} 2>/dev/null || stat -f%z {$backupFile} 2>/dev/null || echo 0"));
        if ($fileSize < 100) {
            throw new Exception("Backup file is too small ({$fileSize} bytes), backup likely failed");
        }

        writeln('');
        writeln('✅ Database backup completed successfully!');

        $fileSizeHuman = trim(run("ls -lh {$backupFile} | awk '{print \$5}'"));
        writeln("📁 Location: {$backupFile}");
        writeln("📊 Size: {$fileSizeHuman}");

        // Clean up old backups (keep only 3 most recent)
        writeln('');
        writeln('🧹 Cleaning up old backups (keeping 3 most recent)...');
        run('cd {{deploy_path}}/shared/backups && ls -t db_backup_*.sql.gz | tail -n +4 | xargs -r rm -f');

        $backupCount = (int) trim(run('cd {{deploy_path}}/shared/backups && ls -1 db_backup_*.sql.gz 2>/dev/null | wc -l'));
        writeln("✅ Total backups on server: {$backupCount}");

    } catch (Exception $e) {
        writeln('');
        writeln('❌ Backup failed: '.$e->getMessage());

        // Clean up failed backup file if it exists
        $fileExists = trim(run("test -f {$backupFile} && echo 'OK' || echo 'FAIL'"));
        if ($fileExists === 'OK') {
            run("rm -f {$backupFile}");
            writeln('🧹 Cleaned up failed backup file');
        }

        throw $e;
    } finally {
        // Always clean up config file
        run("rm -f {$config['config_file']} 2>/dev/null || true");
    }
})->desc('Create database backup before deployment (structure + data)');

task('database:download', function () {
    // Get arguments from environment variables
    $backupSelection = getenv('DEPLOYER_BACKUP_SELECTION') ?: null;
    $downloadMethod = getenv('DEPLOYER_DOWNLOAD_METHOD') ?: null;

    $backup = selectBackup($backupSelection);
    $remoteInfo = getRemoteFileInfo($backup['path']);

    $backupDir = './.deploy/downloads/backups';
    runLocally("mkdir -p {$backupDir}");

    writeln("📥 Downloading {$backup['name']} ({$remoteInfo['human']})...");

    try {
        downloadWithProgress($backup['path'], "{$backupDir}/{$backup['name']}", $remoteInfo['bytes'], $downloadMethod);
        writeln('');
        writeln('💡 To restore locally: php artisan database:restore '.$backup['name']);
    } catch (Exception $e) {
        $localFile = "{$backupDir}/{$backup['name']}";
        if ((int) runLocally("test -f '{$localFile}' && echo 1 || echo 0") === 1) {
            $localSize = (int) runLocally("stat -f%z '{$localFile}' 2>/dev/null || stat -c%s '{$localFile}' 2>/dev/null || echo 0");
            if ($localSize < ($remoteInfo['bytes'] * 0.95)) {
                runLocally("rm -f '{$localFile}'");
                writeln('🧹 Cleaned up partial download');
            } else {
                writeln('📁 File appears complete, keeping download');
            }
        }
        throw new Exception('Download failed: '.$e->getMessage());
    }
})->desc('Download database backup from server to local machine');

task('database:upload', function () {
    // Get arguments from environment variables
    $backupFile = getenv('DEPLOYER_BACKUP_FILE') ?: null;
    $targetServer = getenv('DEPLOYER_TARGET_SERVER') ?: null;
    $sshKey = getenv('DEPLOYER_SSH_KEY') ?: null;
    $remotePath = getenv('DEPLOYER_REMOTE_PATH') ?: '/home/ubuntu/';

    if (! $backupFile) {
        throw new Exception('DEPLOYER_BACKUP_FILE environment variable is required');
    }

    if (! $targetServer) {
        throw new Exception('DEPLOYER_TARGET_SERVER environment variable is required');
    }

    if (! $sshKey) {
        throw new Exception('DEPLOYER_SSH_KEY environment variable is required');
    }

    // Verify local backup file exists
    $fileCheck = (int) runLocally("test -f '{$backupFile}' && echo 1 || echo 0");
    if ($fileCheck !== 1) {
        throw new Exception("Backup file not found: {$backupFile}");
    }

    // Verify SSH key exists
    $keyCheck = (int) runLocally("test -f '{$sshKey}' && echo 1 || echo 0");
    if ($keyCheck !== 1) {
        throw new Exception("SSH key not found: {$sshKey}");
    }

    $backupName = basename($backupFile);
    $localSize = (int) runLocally("stat -f%z '{$backupFile}' 2>/dev/null || stat -c%s '{$backupFile}' 2>/dev/null || echo 0");
    $localSizeHuman = runLocally("ls -lh '{$backupFile}' | awk '{print \$5}'");

    // Ensure remote path ends with /
    $remotePath = rtrim($remotePath, '/').'/';

    writeln("📤 Uploading {$backupName} ({$localSizeHuman}) to {$targetServer}...");
    writeln('');

    try {
        uploadWithProgress($backupFile, $targetServer.':'.$remotePath, $sshKey, $localSize);

        writeln('');
        writeln("📁 Remote location: {$targetServer}:{$remotePath}{$backupName}");
    } catch (Exception $e) {
        throw new Exception('Upload failed: '.$e->getMessage());
    }
})->desc('Upload database backup to remote server');
