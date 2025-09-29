<?php

namespace Deployer;

use Deployer\Exception\Exception;
use Symfony\Component\Console\Output\OutputInterface;

// Database backup task
task('database:backup', function () {
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = "{{deploy_path}}/shared/backups/db_backup_{$timestamp}.sql.gz";

    // Ensure backup directory exists
    run('mkdir -p {{deploy_path}}/shared/backups');

    // Get database configuration
    $defaultConnection = run('cd {{current_path}} && php artisan tinker --execute="echo config(\'database.default\');"');
    $defaultConnection = trim($defaultConnection);
    $dbHost = run("cd {{current_path}} && php artisan tinker --execute=\"echo config('database.connections.{$defaultConnection}.host');\"");
    $dbName = run("cd {{current_path}} && php artisan tinker --execute=\"echo config('database.connections.{$defaultConnection}.database');\"");
    $dbUser = run("cd {{current_path}} && php artisan tinker --execute=\"echo config('database.connections.{$defaultConnection}.username');\"");
    $dbPassword = run("cd {{current_path}} && php artisan tinker --execute=\"echo config('database.connections.{$defaultConnection}.password');\"");

    // Trim the values
    $dbHost = trim($dbHost);
    $dbName = trim($dbName);
    $dbUser = trim($dbUser);
    $dbPassword = trim($dbPassword);

    // Validate and sanitize database configuration values
    if (empty($dbHost) || ! preg_match('/^[a-zA-Z0-9.-]+$/', $dbHost)) {
        throw new Exception("Invalid database host: {$dbHost}");
    }
    if (empty($dbName) || ! preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
        throw new Exception("Invalid database name: {$dbName}");
    }
    if (empty($dbUser) || ! preg_match('/^[a-zA-Z0-9_@.-]+$/', $dbUser)) {
        throw new Exception("Invalid database user: {$dbUser}");
    }
    if (empty($dbPassword)) {
        throw new Exception('Database password cannot be empty');
    }

    // Create temporary MySQL config file to avoid password exposure
    $mysqlConfig = '/tmp/mysql_backup_' . uniqid() . '.cnf';
    run("echo '[client]' > {$mysqlConfig}");
    run("echo 'host={$dbHost}' >> {$mysqlConfig}");
    run("echo 'user={$dbUser}' >> {$mysqlConfig}");
    run("echo 'password={$dbPassword}' >> {$mysqlConfig}");

    // Create database backup using mysqldump with gzip compression (includes structure + data)
    run("mysqldump --defaults-file={$mysqlConfig} --single-transaction --routines --triggers {$dbName} | gzip -8 > {$backupFile}");

    // Clean up config file
    run("rm -f {$mysqlConfig}");

    // Keep only last 3 backups
    run('cd {{deploy_path}}/shared/backups && ls -t db_backup_*.sql.gz | tail -n +4 | xargs -r rm');

    // Get backup file size
    $fileSize = run("ls -lh {$backupFile} | awk '{print \$5}'");
    $fileSize = trim($fileSize);

    writeln("💾 Database backed up to: {$backupFile} ({$fileSize})");
})->desc('Create database backup before deployment (structure + data)');

// Download database backup task
task('database:download', function () {
    // List available backups
    $backupList = run('ls -lt {{deploy_path}}/shared/backups/db_backup_*.sql.gz 2>/dev/null || echo ""');
    if (empty($backupList)) {
        throw new Exception('No database backups found on server');
    }

    writeln('📋 Available database backups:');
    writeln('');

    // Show available backups with sizes
    $backups = run('ls -lht {{deploy_path}}/shared/backups/db_backup_*.sql.gz | head -10');
    $lines = explode("\n", trim($backups));

    foreach ($lines as $index => $line) {
        if (! empty(trim($line))) {
            // Extract filename and size from ls output
            $parts = preg_split('/\s+/', $line);
            $size = $parts[4];
            $filename = basename($parts[8]);
            $number = $index + 1;
            writeln("   {$number}. {$filename} ({$size})");
        }
    }

    writeln('');
    $choice = ask('Enter backup number to download (1-' . count($lines) . ') or press Enter for latest:');

    // Default to latest (first in list) if no choice made
    if (empty($choice)) {
        $choice = 1;
    }

    $choiceIndex = (int) $choice - 1;
    if ($choiceIndex < 0 || $choiceIndex >= count($lines)) {
        throw new Exception('Invalid backup selection');
    }

    // Get the selected backup filename
    $selectedLine = trim($lines[$choiceIndex]);
    $parts = preg_split('/\s+/', $selectedLine);
    $selectedBackup = $parts[8];
    $backupSize = $parts[4];
    $backupName = basename($selectedBackup);

    // Create local backups directory if it doesn't exist
    runLocally('mkdir -p ./backups');

    // Download the backup using rsync with enhanced progress reporting
    $deployVars = [
        'DEPLOY_HOST' => get('hostname'),
        'DEPLOY_USER' => get('remote_user'),
    ];

    // First, get the actual file size from the remote server for accurate progress
    $remoteSizeBytes = (int) trim(run("stat -c%s {$selectedBackup}"));
    $remoteSizeHuman = trim(run("ls -lh {$selectedBackup} | awk '{print \$5}'"));

    // Verify remote file exists and is readable
    $remoteFileCheck = run("test -r {$selectedBackup} && echo 'OK' || echo 'FAIL'");
    if (trim($remoteFileCheck) !== 'OK') {
        throw new Exception("Cannot access backup file on remote server: {$selectedBackup}");
    }

    writeln("📥 Downloading {$backupName} ({$remoteSizeHuman})...");

    // Ask user for download method preference
    writeln("💡 Speed optimization tips:");
    writeln("   • Option 1 (rsync): Best for reliability, resume capability");
    writeln("   • Option 2 (scp): Often faster for large files, no resume");
    writeln("");
    $downloadMethod = ask('Choose download method: (1) Optimized rsync [default] (2) Direct SCP', '1');

    writeln("");

    try {
        $startTime = microtime(true);
        $localFile = "./backups/{$backupName}";

        // Set download command based on user choice
        switch ($downloadMethod) {
            case '2':
                writeln("🚀 Using SCP for maximum speed...");
                // SCP with optimizations:
                // -o Compression=no: Disable compression for gzipped files
                // -o TCPKeepAlive=yes: Keep connection alive
                // -o ServerAliveInterval=60: Send keepalive packets
                $downloadCmd = "scp -o Compression=no -o TCPKeepAlive=yes -o ServerAliveInterval=60 {$deployVars['DEPLOY_USER']}@{$deployVars['DEPLOY_HOST']}:{$selectedBackup} ./backups/";
                break;

            default:
                $downloadMethod = '1';
                break;
        }

        if ($downloadMethod === '1') {
            writeln("⚡ Using optimized rsync (no compression, no bandwidth limit)...");
            // Optimized rsync with maximum compatibility:
            // -a: Archive mode (preserves permissions, times, etc.)
            // -v: Verbose output
            // --partial: Keep partially transferred files for resume capability
            // --inplace: Update destination files in-place (reduces disk I/O)
            // Note: Removed -z flag to disable compression since file is already gzipped
            $downloadCmd = "rsync -av --partial --inplace {$deployVars['DEPLOY_USER']}@{$deployVars['DEPLOY_HOST']}:{$selectedBackup} ./backups/";
        }

        writeln("💡 This may take a while for large files. Monitoring progress every 30 seconds...");

        // Start the download process in background
        $pid = (int) runLocally("({$downloadCmd} > /tmp/rsync_output_" . getmypid() . ".log 2>&1 & echo \$!) | tail -1");

        if ($pid <= 0) {
            throw new Exception("Failed to start rsync process");
        }

        writeln("📥 Download started (PID: {$pid})");

        // Monitor progress by checking file size
        $lastSize = 0;
        $stagnantCount = 0;
        $maxStagnantChecks = 10; // Allow 300 seconds (5 minutes) of no progress before considering it stagnant

        while (true) {
            // Check if process is still running
            $processCheck = (int) runLocally("ps -p {$pid} > /dev/null 2>&1; echo \$?");

            if ($processCheck !== 0) {
                // Process finished, it may have completed successfully
                $exitStatus = 0; // We'll check the actual result by verifying the file
                break;
            }

            // Check current file size
            $fileExists = (int) runLocally("test -f '{$localFile}' && echo 1 || echo 0");
            if ($fileExists) {
                $currentSize = (int) runLocally("stat -f%z '{$localFile}' 2>/dev/null || stat -c%s '{$localFile}' 2>/dev/null || echo 0");

                if ($currentSize > 0 && $remoteSizeBytes > 0) {
                    $currentMB = round((float)($currentSize / 1024 / 1024), 1);
                    $totalMB = round((float)($remoteSizeBytes / 1024 / 1024), 1);
                    $percent = round((float)(($currentSize / $remoteSizeBytes) * 100), 1);

                    $progressMsg = "📊 Progress: {$percent}% ({$currentMB} MB / {$totalMB} MB)";

                    // Check for progress
                    if ($currentSize > $lastSize) {
                        $bytesTransferred = $currentSize - $lastSize;
                        $speedMBps = $bytesTransferred > 0 ? round((float)($bytesTransferred / 1024 / 1024 / 30), 2) : 0; // MB per second over 30 second interval
                        $progressMsg .= " | Speed: {$speedMBps} MB/s";
                        $stagnantCount = 0;
                    } else {
                        $stagnantCount++;
                        if ($stagnantCount >= $maxStagnantChecks) {
                            throw new Exception("Download appears to be stagnant (no progress for " . ($stagnantCount * 30) . " seconds)");
                        }
                    }

                    writeln($progressMsg);
                    $lastSize = $currentSize;
                }
            }

            sleep(30); // Check every 30 seconds
        }

        // Check if download was successful by verifying file exists and checking log
        $logContent = runLocally("cat /tmp/rsync_output_" . getmypid() . ".log 2>/dev/null || echo 'No log available'");

        // Check if the log indicates success (rsync completed)
        $hasError = strpos($logContent, 'rsync error:') !== false ||
                   strpos($logContent, 'failed') !== false ||
                   strpos($logContent, 'No such file') !== false;

        if ($hasError) {
            runLocally("rm -f /tmp/rsync_output_" . getmypid() . ".log");
            throw new Exception("rsync failed. Log: {$logContent}");
        }

        // Clean up log file
        runLocally("rm -f /tmp/rsync_output_" . getmypid() . ".log");

        $endTime = microtime(true);
        $downloadTime = round((float)($endTime - $startTime), 2);

        // Verify the downloaded file
        $localFile = "./backups/{$backupName}";
        $fileExists = runLocally("test -f '{$localFile}' && echo 1 || echo 0");

        if ((int)$fileExists !== 1) {
            throw new Exception("Download failed: Local file not found");
        }

        $localSize = (int) runLocally("stat -f%z '{$localFile}' 2>/dev/null || stat -c%s '{$localFile}' 2>/dev/null || echo 0");

        // Allow for small size differences (rsync may report slightly different sizes)
        $sizeDiff = abs($localSize - $remoteSizeBytes);
        $tolerance = max(1024, $remoteSizeBytes * 0.001); // 1KB or 0.1% tolerance

        if ($sizeDiff > $tolerance) {
            throw new Exception("Download failed: File size mismatch (local: {$localSize} bytes, remote: {$remoteSizeBytes} bytes, difference: {$sizeDiff} bytes)");
        }

        // Calculate download speed
        $downloadSpeedMBps = $downloadTime > 0 ? ($localSize / 1024 / 1024) / $downloadTime : 0;
        $downloadSpeedFormatted = round((float)$downloadSpeedMBps, 2);

        writeln("");
        writeln("✅ Database backup downloaded successfully!");
        writeln("📁 Location: ./backups/{$backupName}");
        writeln("📊 Size: {$remoteSizeHuman}");
        writeln("⏱️  Time: {$downloadTime}s");
        writeln("🚀 Speed: {$downloadSpeedFormatted} MB/s");
    } catch (Exception $e) {
        // Only clean up if it's actually a partial/failed download
        $localFile = "./backups/{$backupName}";
        $fileExists = runLocally("test -f '{$localFile}' && echo 1 || echo 0");

        if ((int)$fileExists === 1) {
            $localSize = (int) runLocally("stat -f%z '{$localFile}' 2>/dev/null || stat -c%s '{$localFile}' 2>/dev/null || echo 0");

            // Only delete if file is significantly smaller than expected (indicating partial download)
            if ($localSize < ($remoteSizeBytes * 0.95)) {
                runLocally("rm -f '{$localFile}'");
                writeln("🧹 Cleaned up partial download");
            } else {
                writeln("📁 File appears complete, keeping download");
            }
        }

        throw new Exception("Download failed: " . $e->getMessage());
    }
    writeln('');
    writeln('💡 To restore locally: zcat ./backups/' . $backupName . ' | mysql -u[user] -p[password] [database]');
})->desc('Download database backup from server to local machine');