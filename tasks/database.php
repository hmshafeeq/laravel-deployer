<?php

namespace Deployer;

use Deployer\Exception\Exception;

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

    // Download the backup using rsync (more reliable than scp for large files)
    $deployVars = [
        'DEPLOY_HOST' => get('hostname'),
        'DEPLOY_USER' => get('remote_user'),
    ];

    $downloadCmd = "rsync -avz --progress {$deployVars['DEPLOY_USER']}@{$deployVars['DEPLOY_HOST']}:{$selectedBackup} ./backups/";

    writeln("📥 Downloading {$backupName} ({$backupSize})...");
    runLocally($downloadCmd, timeout: 1800); // 30 minutes timeout for large files

    writeln("✅ Database backup downloaded to: ./backups/{$backupName} ({$backupSize})");
    writeln('');
    writeln('💡 To restore locally: zcat ./backups/' . $backupName . ' | mysql -u[user] -p[password] [database]');
})->desc('Download database backup from server to local machine');