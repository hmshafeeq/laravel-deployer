<?php

namespace Deployer;

use Deployer\Exception\Exception;

// Resource monitoring task
task('health:check-resources', function () {
    $environment = currentHost()->getAlias();

    if ($environment === 'local') {
        writeln('🔍 Checking server resources...');
        writeln('<comment>⏭️  Skipping detailed resource checks for local environment</comment>');
        writeln('✅ Local environment checks passed');
        writeln('');
        return;
    }

    writeln('🔍 Checking server resources...');

    // Check disk space
    $diskUsage = run('df -h {{deploy_path}} | tail -1');
    $diskInfo = preg_split('/\s+/', trim($diskUsage));

    // Handle different df output formats (Linux vs macOS)
    $usedPercentIndex = count($diskInfo) === 6 ? 4 : 3;
    $availableIndex = count($diskInfo) === 6 ? 3 : 2;

    if (!isset($diskInfo[$usedPercentIndex])) {
        writeln('<comment>⚠️  Could not parse disk usage information</comment>');
    } else {
        $usedPercent = rtrim($diskInfo[$usedPercentIndex], '%');
        $available = $diskInfo[$availableIndex] ?? 'unknown';

        writeln("💾 Disk Usage: {$diskInfo[$usedPercentIndex]} used, {$available} available");

        if ((int) $usedPercent > 90) {
            throw new Exception("❌ Disk space critical! {$usedPercent}% used. Please free up space before deployment.");
        }

        if ((int) $usedPercent > 80) {
            writeln("⚠️  Warning: Disk usage is high ({$usedPercent}%). Consider cleaning up old releases.");
        } else {
            writeln('✅ Disk space OK');
        }
    }

    // Check memory usage
    $memInfo = run('free -h | grep -E "^Mem:|^Swap:" || echo "Memory info unavailable"');
    if (strpos($memInfo, 'unavailable') === false) {
        $lines = explode("\n", trim($memInfo));
        foreach ($lines as $line) {
            if (strpos($line, 'Mem:') === 0) {
                $memParts = preg_split('/\s+/', $line);
                writeln("🧠 Memory: {$memParts[2]} used / {$memParts[1]} total ({$memParts[3]} available)");
            }
            if (strpos($line, 'Swap:') === 0) {
                $swapParts = preg_split('/\s+/', $line);
                if ($swapParts[1] !== '0B') {
                    writeln("💾 Swap: {$swapParts[2]} used / {$swapParts[1]} total");
                }
            }
        }
    }

    writeln('');
})->desc('Check server resources (disk space, memory) before deployment');

// Combined health check and smoke test task
task('health:check-endpoints', function () {
    $appUrl = run('cd {{current_path}} && php artisan tinker --execute="echo config(\"app.url\");"');
    $appUrl = trim($appUrl);

    writeln('🔍 Running deployment health checks...');
    writeln('');

    // First, check the dedicated health endpoint with detailed output
    $healthUrl = rtrim($appUrl, '/') . '/health';

    // Health check with timeout, retry logic, and proper error handling
    $maxRetries = 3;
    $healthStatusCode = null;
    $healthResponse = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        writeln("🔄 Health check attempt {$attempt}/{$maxRetries}...");

        try {
            $healthResponse = run("timeout 30 curl -s --max-time 10 --connect-timeout 5 {$healthUrl}");
            $healthStatusCode = run("timeout 30 curl -s --max-time 10 --connect-timeout 5 -o /dev/null -w '%{http_code}' {$healthUrl}");

            if ($healthStatusCode === '200') {
                break; // Success, exit retry loop
            }

            if ($attempt < $maxRetries) {
                writeln("⚠️  Health check failed (HTTP {$healthStatusCode}), retrying in 5 seconds...");
                sleep(5);
            }
        } catch (Exception $e) {
            if ($attempt < $maxRetries) {
                writeln('⚠️  Health check connection failed, retrying in 5 seconds...');
                sleep(5);
            } else {
                throw new Exception("Health endpoint connection failed after {$maxRetries} attempts: " . $e->getMessage());
            }
        }
    }

    if ($healthStatusCode !== '200') {
        throw new Exception("Health endpoint failed after {$maxRetries} attempts. Final HTTP response: {$healthStatusCode}. Response body: {$healthResponse}");
    }

    // Pretty print the health check JSON
    writeln('📊 Health Status:');
    $prettyHealth = run("echo '{$healthResponse}' | python3 -m json.tool 2>/dev/null || echo '{$healthResponse}'");
    writeln($prettyHealth);
    writeln('');

    // Then run smoke tests on all critical endpoints
    writeln('🧪 Testing critical endpoints:');
    $endpoints = [
        '/' => 'Home page',
        '/admin/login' => 'Admin login',
        '/user/login' => 'User login',
        '/health' => 'Health check',
    ];

    foreach ($endpoints as $endpoint => $description) {
        $url = rtrim($appUrl, '/') . $endpoint;
        $response = run("curl -s -o /dev/null -w '%{http_code}' {$url} || echo 'FAILED'");

        if (! in_array($response, ['200', '302', '401'])) { // Allow redirects and auth pages
            throw new Exception("Smoke test failed for {$endpoint} ({$description}). HTTP: {$response}");
        }

        writeln("   ✅ {$endpoint} ({$description}) - HTTP {$response}");
    }

    writeln('');
    writeln('✅ All health checks passed!');
})->desc('Run comprehensive health checks and endpoint smoke tests');