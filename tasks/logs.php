<?php

namespace Deployer;

use Symfony\Component\Console\Input\InputOption;

option('logfile', null, InputOption::VALUE_OPTIONAL, 'Log file to display');
option('lines', null, InputOption::VALUE_OPTIONAL, 'How many lines of the logfile to display (use 0 to view all)');
option('search', null, InputOption::VALUE_OPTIONAL, 'Only return lines that match the search string');
option('follow', null, InputOption::VALUE_NONE, 'Follow log file');
option('destination', null, InputOption::VALUE_OPTIONAL, 'Destination path');

// Backward compatibility
before('logs:app', function () {
    normaliseLogFilesSetting();
});

// Log monitoring task
task('logs:check', function () {
    writeln('📊 Checking application logs (last 7 days)...');
    writeln('');

    // Get last 7 days of log files
    $dates = [];
    for ($i = 0; $i < 7; $i++) {
        $dates[] = date('Y-m-d', strtotime("-{$i} days"));
    }

    $totalErrors = 0;
    $totalWarnings = 0;
    $totalInfo = 0;
    $recentIssues = [];

    foreach ($dates as $date) {
        $logFile = "{{current_path}}/storage/logs/laravel-{$date}.log";
        $logExists = run("test -f {$logFile} && echo 'exists' || echo 'missing'");

        if (trim($logExists) === 'exists') {
            // Count different log levels
            $errors = (int) run("grep -c '\.ERROR:' {$logFile} 2>/dev/null || echo 0");
            $warnings = (int) run("grep -c '\.WARNING:' {$logFile} 2>/dev/null || echo 0");
            $info = (int) run("grep -c '\.INFO:' {$logFile} 2>/dev/null || echo 0");

            $totalErrors += $errors;
            $totalWarnings += $warnings;
            $totalInfo += $info;

            if ($errors > 0 || $warnings > 0) {
                writeln("📅 {$date}: 🔴 {$errors} errors, 🟡 {$warnings} warnings, ℹ️  {$info} info");

                // Get recent errors/warnings (last 10 from this day)
                if ($errors > 0) {
                    $errorLines = run("grep '\.ERROR:' {$logFile} | tail -5 | sed 's/.*\.ERROR: //' | sed 's/ {\"exception.*//' | sed 's/\\[stacktrace\\].*//'");
                    if (! empty(trim($errorLines))) {
                        foreach (explode("\n", trim($errorLines)) as $line) {
                            if (! empty(trim($line))) {
                                $recentIssues[] = "🔴 [{$date}] ".trim($line);
                            }
                        }
                    }
                }

                if ($warnings > 0) {
                    $warningLines = run("grep '\.WARNING:' {$logFile} | tail -3 | sed 's/.*\.WARNING: //' | sed 's/ {\"exception.*//' | sed 's/\\[stacktrace\\].*//'");
                    if (! empty(trim($warningLines))) {
                        foreach (explode("\n", trim($warningLines)) as $line) {
                            if (! empty(trim($line))) {
                                $recentIssues[] = "🟡 [{$date}] ".trim($line);
                            }
                        }
                    }
                }
            } else {
                writeln("📅 {$date}: ✅ No errors or warnings, ℹ️  {$info} info");
            }
        }
    }

    writeln('');
    writeln('📈 Summary (Last 7 days):');
    writeln("   🔴 Total Errors: {$totalErrors}");
    writeln("   🟡 Total Warnings: {$totalWarnings}");
    writeln("   ℹ️  Total Info: {$totalInfo}");

    if (! empty($recentIssues)) {
        writeln('');
        writeln('🚨 Recent Issues:');
        $displayCount = min(10, count($recentIssues));
        for ($i = 0; $i < $displayCount; $i++) {
            writeln('   '.$recentIssues[$i]);
        }
        if (count($recentIssues) > 10) {
            writeln('   ... and '.(count($recentIssues) - 10).' more');
        }
    }

    writeln('');
    if ($totalErrors > 0) {
        writeln('❌ Found errors in logs - review recommended');
    } elseif ($totalWarnings > 0) {
        writeln('⚠️  Found warnings in logs - monitoring suggested');
    } else {
        writeln('✅ No errors or warnings found in recent logs');
    }
})->desc('Check application logs for errors and warnings (last 7 days)');

desc('Search a log file');
task('logs:search', function () {

    // Get array of log files
    if (! has('log_files')) {
        warning('Please specify "log_files" option in deploy.php to view log files.');

        return;
    }
    $logfiles = getLogFilesSettingAsArray();
    $logfiles = expandLogFiles($logfiles);

    // Select log file
    $logfile = getLogfileLogsOption($logfiles, 'search');

    // Search terms
    if (! empty(input()->getOption('search'))) {
        $search = input()->getOption('search');
    } else {
        $search = ask('Enter search term');
        while (empty($search)) {
            warning('Please specify a search term');
            $search = ask('Enter search term');
        }
    }

    // Set number of lines to display
    if (input()->getOption('lines') !== null) {
        $lines = (int) input()->getOption('lines');
    } else {
        $lines = 20;
    }

    // Search log file
    cd('{{current_path}}');
    if ($lines === 0) {
        run(sprintf('grep --color=always -i %s %s | less', $search, $logfile), real_time_output: true);
    } else {
        run(sprintf('grep --color=always -i %s %s | tail -n %d', $search, $logfile, $lines), real_time_output: true);
    }

    // Build example command
    $command = sprintf('dep logs:search %s --logfile=%s --lines=%d --search="%s"', currentHost(), $logfile, $lines, $search);
    writeln(sprintf('<info>Run again with: %s</info>', $command));
});

desc('Download a log file');
task('logs:download', function () {

    // Get array of log files
    if (! has('log_files')) {
        warning('Please specify "log_files" option in deploy.php to view log files.');

        return;
    }
    $logfiles = getLogFilesSettingAsArray();
    $logfiles = expandLogFiles($logfiles);

    // Select log file
    $logfile = getLogfileLogsOption($logfiles, 'download');

    // Destination
    if (input()->getOption('destination') !== null) {
        $destination = input()->getOption('destination');
    } else {
        $destination = ask('Enter destination path', './');
    }

    // Ensure destination directory exists
    if (! str_ends_with($destination, '/')) {
        // If destination is a file path, get the directory
        $destinationDir = dirname($destination);
    } else {
        // If destination is a directory path
        $destinationDir = rtrim($destination, '/');
    }

    // Create directory if it doesn't exist
    runLocally("mkdir -p {$destinationDir}");

    // Download log file
    download('{{current_path}}/'.$logfile, $destination);
    writeln('Log file downloaded to: '.$destination);

    // Build example command
    $command = sprintf('dep logs:download %s --logfile=%s', currentHost(), $logfile);
    writeln(sprintf('<info>Run again with: %s</info>', $command));
});

/**
 * Normalise log_files setting so it works with default Deployer tasks (that expect a string)
 */
function normaliseLogFilesSetting(): void
{
    if (! has('log_files')) {
        return;
    }
    $logfiles = get('log_files');
    if (is_array($logfiles)) {
        set('log_files', implode(' ', $logfiles));
    }
}

/**
 * Return log_files setting as an array so it works with these tasks
 *
 * @return void
 */
function getLogFilesSettingAsArray(): array
{
    if (! has('log_files')) {
        return [];
    }
    $logfiles = get('log_files');
    if (! is_array($logfiles)) {
        $logfiles = explode(' ', $logfiles);
    }

    return $logfiles;
}

/**
 * Expand logfiles array to include any wildcard (*) files
 *
 *
 * @throws Exception\Exception
 * @throws Exception\RunException
 * @throws Exception\TimeoutException
 */
function expandLogFiles(array $logfiles): array
{
    foreach ($logfiles as $key => $file) {
        if (str_contains($file, '*')) {
            $path = dirname($file);
            $file = basename($file);

            // Remove wildcard, so we can replace it with actual files
            unset($logfiles[$key]);

            // Test folder exists
            if (! test(sprintf('cd {{current_path}} && [ -d %s ]', $path))) {
                writeln('<error>Logs directory does not exist: '.$path.'</error>');

                continue;
            }

            // Test files exist in folder
            $numFiles = run(sprintf('cd {{current_path}} && ls -A %s | wc -l', $path));
            if ($numFiles < 1) {
                writeln('<info>No logfiles exist in: '.$path.'</info>');

                continue;
            }

            // Expand logfiles from wildcard
            $output = run(sprintf('cd {{current_path}}/%s && ls %s', $path, $file));
            $files = explode("\n", $output);
            if (! empty($files)) {
                array_walk($files, function (&$value) use ($path) {
                    $value = $path.DIRECTORY_SEPARATOR.$value;
                });
                $logfiles = array_merge($logfiles, $files);
            }
        }
    }

    return $logfiles;
}

function getLogfileLogsOption(array $logfiles, string $action)
{
    if (! empty(input()->getOption('logfile'))) {
        return input()->getOption('logfile');
    } elseif (count($logfiles) > 1) {
        return askChoice('Choose a log file to '.$action, $logfiles);
    } else {
        return $logfiles[0];
    }
}

function getLinesLogsOptions(): int
{
    if (! empty(input()->getOption('lines'))) {
        return (int) input()->getOption('lines');
    } else {
        return (int) ask('How many lines to display (0 to view all)', '20');
    }
}
