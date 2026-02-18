<?php

namespace Shaf\LaravelDeployer\Actions;

use Shaf\LaravelDeployer\Data\DeploymentConfig;
use Shaf\LaravelDeployer\Data\DiagnoseCheck;
use Shaf\LaravelDeployer\Data\DiagnoseResult;
use Shaf\LaravelDeployer\Services\CommandService;

/**
 * Diagnose server permissions and configuration.
 */
class DiagnoseAction extends Action
{
    private DiagnoseResult $result;

    private bool $fullMode = false;

    public function __construct(
        protected CommandService $cmd,
        protected DeploymentConfig $config,
        private string $deployUser = 'ubuntu',
        private string $webGroup = 'www-data',
    ) {
        parent::__construct($cmd, $config);
    }

    /**
     * Run diagnostic checks.
     */
    public function execute(bool $fullMode = false): DiagnoseResult
    {
        $this->fullMode = $fullMode;
        $this->result = new DiagnoseResult(
            $this->config->environment->value,
            $this->config->deployPath,
            $this->deployUser,
            $this->webGroup,
        );

        // User & Group checks
        $this->checkDeployUser();
        $this->checkWebUser();
        $this->checkUserInWebGroup();
        $this->checkWebUserInDeployGroup();

        // Umask checks
        $this->checkSystemUmask();
        $this->checkPhpFpmUmask();

        // Directory checks
        $this->checkSiteRootOwnership();
        $this->checkReleasesOwnership();
        $this->checkSharedStorageOwnership();
        $this->checkBootstrapCacheOwnership();

        // Permission analysis (quick mode does counts, full mode lists files)
        $this->checkFilesNotGroupWritable();
        $this->checkDirectoriesWithoutSetgid();
        $this->checkFilesOwnedByWebUser();
        $this->checkFilesOwnedByRoot();

        // Comprehensive permission enforcement
        $this->checkComprehensiveDirectoryPermissions();
        $this->checkComprehensiveFilePermissions();
        $this->checkWritableStoragePaths();

        // Filesystem check
        $this->checkFilesystem();

        return $this->result;
    }

    // =========================================================================
    // User & Group Checks
    // =========================================================================

    private function checkDeployUser(): void
    {
        try {
            $output = $this->cmd->remote("id {$this->deployUser}");
            // Parse: uid=1000(ubuntu) gid=1000(ubuntu) groups=...
            if (preg_match('/uid=(\d+)\(([^)]+)\)/', $output, $matches)) {
                $this->result->addCheck(
                    'User & Group Configuration',
                    DiagnoseCheck::pass(
                        'Deploy user exists',
                        "Deploy user: {$this->deployUser} (uid={$matches[1]})",
                        $output
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'User & Group Configuration',
                DiagnoseCheck::fail(
                    'Deploy user exists',
                    "Deploy user '{$this->deployUser}' not found",
                    null,
                    'User should exist',
                    "sudo useradd -m -s /bin/bash {$this->deployUser}"
                )
            );
        }
    }

    private function checkWebUser(): void
    {
        try {
            $output = $this->cmd->remote("id {$this->webGroup}");
            if (preg_match('/uid=(\d+)\(([^)]+)\)/', $output, $matches)) {
                $this->result->addCheck(
                    'User & Group Configuration',
                    DiagnoseCheck::pass(
                        'Web user exists',
                        "Web user: {$this->webGroup} (uid={$matches[1]})",
                        $output
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'User & Group Configuration',
                DiagnoseCheck::fail(
                    'Web user exists',
                    "Web user '{$this->webGroup}' not found",
                    null,
                    'User should exist'
                )
            );
        }
    }

    private function checkUserInWebGroup(): void
    {
        try {
            $output = $this->cmd->remote("groups {$this->deployUser}");
            // Output: "ubuntu : ubuntu adm ... www-data ..."
            $inGroup = str_contains($output, $this->webGroup);

            if ($inGroup) {
                $this->result->addCheck(
                    'User & Group Configuration',
                    DiagnoseCheck::pass(
                        'Deploy user in web group',
                        "{$this->deployUser} is member of {$this->webGroup} group",
                        $output
                    )
                );
            } else {
                $this->result->addCheck(
                    'User & Group Configuration',
                    DiagnoseCheck::fail(
                        'Deploy user in web group',
                        "{$this->deployUser} is NOT in {$this->webGroup} group",
                        $output,
                        "{$this->deployUser} should be in {$this->webGroup} group",
                        "sudo usermod -aG {$this->webGroup} {$this->deployUser}\n# Reconnect SSH for changes to take effect"
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'User & Group Configuration',
                DiagnoseCheck::skip('Deploy user in web group', 'Could not check group membership')
            );
        }
    }

    private function checkWebUserInDeployGroup(): void
    {
        try {
            $output = $this->cmd->remote("groups {$this->webGroup}");
            // Output: "www-data : www-data ubuntu ..."
            $inGroup = str_contains($output, $this->deployUser);

            if ($inGroup) {
                $this->result->addCheck(
                    'User & Group Configuration',
                    DiagnoseCheck::pass(
                        'Web user in deploy group',
                        "{$this->webGroup} is member of {$this->deployUser} group",
                        $output
                    )
                );
            } else {
                $this->result->addCheck(
                    'User & Group Configuration',
                    DiagnoseCheck::fail(
                        'Web user in deploy group',
                        "{$this->webGroup} is NOT in {$this->deployUser} group (bidirectional membership missing)",
                        $output,
                        "{$this->webGroup} should be in {$this->deployUser} group",
                        "sudo usermod -aG {$this->deployUser} {$this->webGroup}\n# Reconnect SSH for changes to take effect"
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'User & Group Configuration',
                DiagnoseCheck::skip('Web user in deploy group', 'Could not check group membership')
            );
        }
    }

    // =========================================================================
    // Umask Checks
    // =========================================================================

    private function checkSystemUmask(): void
    {
        try {
            $output = trim($this->cmd->remote('umask'));
            // Good umask values: 0002, 0022 (0002 is ideal for group-writable)
            $isGood = in_array($output, ['0002', '002', '0022', '022']);
            $isIdeal = in_array($output, ['0002', '002']);

            if ($isIdeal) {
                $this->result->addCheck(
                    'Umask Configuration',
                    DiagnoseCheck::pass(
                        'System umask',
                        "System umask: {$output} (group-writable)",
                        $output
                    )
                );
            } elseif ($isGood) {
                $this->result->addCheck(
                    'Umask Configuration',
                    DiagnoseCheck::warn(
                        'System umask',
                        "System umask: {$output} (consider 0002 for group-writable)",
                        $output,
                        '0002'
                    )
                );
            } else {
                $this->result->addCheck(
                    'Umask Configuration',
                    DiagnoseCheck::fail(
                        'System umask',
                        "System umask: {$output} (may cause permission issues)",
                        $output,
                        '0002 or 0022',
                        "# Add to /etc/profile or ~/.bashrc:\numask 0002"
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'Umask Configuration',
                DiagnoseCheck::skip('System umask', 'Could not check umask')
            );
        }
    }

    private function checkPhpFpmUmask(): void
    {
        try {
            $output = $this->cmd->remote("grep -i umask /etc/php/*/fpm/pool.d/*.conf 2>/dev/null || echo 'NOT_SET'");

            if (str_contains($output, 'NOT_SET') || empty(trim($output))) {
                $this->result->addCheck(
                    'Umask Configuration',
                    DiagnoseCheck::warn(
                        'PHP-FPM umask',
                        'PHP-FPM umask: not set (inherits system umask)',
                        'Not configured'
                    )
                );
            } else {
                $this->result->addCheck(
                    'Umask Configuration',
                    DiagnoseCheck::pass(
                        'PHP-FPM umask',
                        'PHP-FPM umask is configured',
                        $output
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'Umask Configuration',
                DiagnoseCheck::skip('PHP-FPM umask', 'Could not check PHP-FPM configuration')
            );
        }
    }

    // =========================================================================
    // Directory Ownership Checks
    // =========================================================================

    private function checkSiteRootOwnership(): void
    {
        $this->checkDirectoryOwnership('Site root', $this->config->deployPath);
    }

    private function checkReleasesOwnership(): void
    {
        $this->checkDirectoryOwnership('Releases directory', $this->config->deployPath.'/releases');
    }

    private function checkSharedStorageOwnership(): void
    {
        $this->checkDirectoryOwnership('Shared storage', $this->config->deployPath.'/shared/storage');
    }

    private function checkBootstrapCacheOwnership(): void
    {
        $this->checkDirectoryOwnership('Bootstrap cache', $this->config->deployPath.'/current/bootstrap/cache');
    }

    private function checkDirectoryOwnership(string $name, string $path): void
    {
        try {
            $escapedPath = CommandService::escapePath($path);
            $output = $this->cmd->remote("stat -c '%U:%G %a' {$escapedPath} 2>/dev/null || echo 'NOT_FOUND'");

            if (str_contains($output, 'NOT_FOUND')) {
                $this->result->addCheck(
                    'Directory Permissions',
                    DiagnoseCheck::skip($name, "Directory not found: {$path}")
                );

                return;
            }

            // Parse: "ubuntu:www-data 2775" or "ubuntu:www-data 775"
            if (preg_match('/^(\w+):(\w+)\s+(\d+)/', $output, $matches)) {
                $owner = $matches[1];
                $group = $matches[2];
                $mode = $matches[3];

                $ownerOk = $owner === $this->deployUser;
                $groupOk = $group === $this->webGroup;
                $modeOk = in_array($mode, ['775', '2775', '755', '2755']);

                if ($ownerOk && $groupOk) {
                    $this->result->addCheck(
                        'Directory Permissions',
                        DiagnoseCheck::pass(
                            $name,
                            "{$owner}:{$group} ({$mode})",
                            $output
                        )
                    );
                } else {
                    $this->result->addCheck(
                        'Directory Permissions',
                        DiagnoseCheck::fail(
                            $name,
                            "{$owner}:{$group} ({$mode})",
                            $output,
                            "{$this->deployUser}:{$this->webGroup}",
                            "sudo chown {$this->deployUser}:{$this->webGroup} {$path}"
                        )
                    );
                }
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'Directory Permissions',
                DiagnoseCheck::skip($name, "Could not check: {$e->getMessage()}")
            );
        }
    }

    // =========================================================================
    // Permission Analysis
    // =========================================================================

    private function checkFilesNotGroupWritable(): void
    {
        $path = $this->config->deployPath.'/current';

        try {
            $escapedPath = CommandService::escapePath($path);
            $output = trim($this->cmd->remote("find {$escapedPath} -type f ! -perm -g+w 2>/dev/null | wc -l"));
            $count = (int) $output;

            if ($count === 0) {
                $this->result->addCheck(
                    'Permission Analysis',
                    DiagnoseCheck::pass(
                        'Files group-writable',
                        'All files are group-writable',
                        '0 files without g+w'
                    )
                );
            } else {
                $this->result->addCheck(
                    'Permission Analysis',
                    DiagnoseCheck::fail(
                        'Files group-writable',
                        "{$count} files are not group-writable",
                        "{$count} files",
                        '0 files',
                        "sudo find {$path} -type f -exec chmod g+w {} \\;"
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'Permission Analysis',
                DiagnoseCheck::skip('Files group-writable', 'Could not check')
            );
        }
    }

    private function checkDirectoriesWithoutSetgid(): void
    {
        $path = $this->config->deployPath.'/current';

        try {
            $escapedPath = CommandService::escapePath($path);
            $output = trim($this->cmd->remote("find {$escapedPath} -type d ! -perm -2000 2>/dev/null | wc -l"));
            $count = (int) $output;

            if ($count === 0) {
                $this->result->addCheck(
                    'Permission Analysis',
                    DiagnoseCheck::pass(
                        'Directories have setgid',
                        'All directories have setgid bit',
                        '0 dirs without setgid'
                    )
                );
            } else {
                $this->result->addCheck(
                    'Permission Analysis',
                    DiagnoseCheck::fail(
                        'Directories have setgid',
                        "{$count} directories without setgid bit (required for group inheritance)",
                        "{$count} directories",
                        '0 directories',
                        "sudo find {$path} -type d -exec chmod g+s {} \\;"
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'Permission Analysis',
                DiagnoseCheck::skip('Directories have setgid', 'Could not check')
            );
        }
    }

    private function checkFilesOwnedByWebUser(): void
    {
        $path = $this->config->deployPath.'/releases';

        try {
            $escapedPath = CommandService::escapePath($path);
            $output = trim($this->cmd->remote("find {$escapedPath} -user {$this->webGroup} -type f 2>/dev/null | wc -l"));
            $count = (int) $output;

            if ($count === 0) {
                $this->result->addCheck(
                    'Permission Analysis',
                    DiagnoseCheck::pass(
                        "Files owned by {$this->webGroup}",
                        "No files owned by {$this->webGroup} in releases",
                        '0 files'
                    )
                );
            } else {
                $this->result->addCheck(
                    'Permission Analysis',
                    DiagnoseCheck::fail(
                        "Files owned by {$this->webGroup}",
                        "{$count} files owned by {$this->webGroup} (hardlink risk)",
                        "{$count} files",
                        '0 files',
                        "sudo chown -R {$this->deployUser}:{$this->webGroup} {$path}"
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'Permission Analysis',
                DiagnoseCheck::skip("Files owned by {$this->webGroup}", 'Could not check')
            );
        }
    }

    private function checkFilesOwnedByRoot(): void
    {
        $path = $this->config->deployPath.'/current';

        try {
            $escapedPath = CommandService::escapePath($path);
            $output = trim($this->cmd->remote("find {$escapedPath} -user root -type f 2>/dev/null | wc -l"));
            $count = (int) $output;

            if ($count === 0) {
                $this->result->addCheck(
                    'Permission Analysis',
                    DiagnoseCheck::pass(
                        'Files owned by root',
                        'No files owned by root',
                        '0 files'
                    )
                );
            } else {
                $this->result->addCheck(
                    'Permission Analysis',
                    DiagnoseCheck::fail(
                        'Files owned by root',
                        "{$count} files owned by root",
                        "{$count} files",
                        '0 files',
                        "sudo chown -R {$this->deployUser}:{$this->webGroup} {$path}"
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'Permission Analysis',
                DiagnoseCheck::skip('Files owned by root', 'Could not check')
            );
        }
    }

    // =========================================================================
    // Comprehensive Permission Enforcement
    // =========================================================================

    private function checkComprehensiveDirectoryPermissions(): void
    {
        $path = $this->config->deployPath.'/current';

        try {
            $escapedPath = CommandService::escapePath($path);
            // Find directories not set to 2775 or 775
            $output = trim($this->cmd->remote("find {$escapedPath} -type d ! \\( -perm 2775 -o -perm 775 \\) 2>/dev/null | wc -l"));
            $count = (int) $output;

            if ($count === 0) {
                $this->result->addCheck(
                    'Comprehensive Permission Enforcement',
                    DiagnoseCheck::pass(
                        'Directory permissions standardized',
                        'All directories have correct permissions (2775/775)',
                        '0 directories with incorrect permissions'
                    )
                );
            } else {
                $this->result->addCheck(
                    'Comprehensive Permission Enforcement',
                    DiagnoseCheck::fail(
                        'Directory permissions standardized',
                        "{$count} directories need permission normalization",
                        "{$count} directories",
                        'All directories should be 2775',
                        "sudo find {$path} -type d -exec chmod 2775 {} \\;"
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'Comprehensive Permission Enforcement',
                DiagnoseCheck::skip('Directory permissions standardized', 'Could not check')
            );
        }
    }

    private function checkComprehensiveFilePermissions(): void
    {
        $path = $this->config->deployPath.'/current';

        try {
            $escapedPath = CommandService::escapePath($path);
            // Find files not set to 664 or 644
            $output = trim($this->cmd->remote("find {$escapedPath} -type f ! \\( -perm 664 -o -perm 644 \\) 2>/dev/null | wc -l"));
            $count = (int) $output;

            if ($count === 0) {
                $this->result->addCheck(
                    'Comprehensive Permission Enforcement',
                    DiagnoseCheck::pass(
                        'File permissions standardized',
                        'All files have correct permissions (664/644)',
                        '0 files with incorrect permissions'
                    )
                );
            } else {
                $this->result->addCheck(
                    'Comprehensive Permission Enforcement',
                    DiagnoseCheck::fail(
                        'File permissions standardized',
                        "{$count} files need permission normalization",
                        "{$count} files",
                        'All files should be 664',
                        "sudo find {$path} -type f -exec chmod 664 {} \\;"
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'Comprehensive Permission Enforcement',
                DiagnoseCheck::skip('File permissions standardized', 'Could not check')
            );
        }
    }

    private function checkWritableStoragePaths(): void
    {
        $paths = [
            $this->config->deployPath.'/shared/storage',
            $this->config->deployPath.'/current/bootstrap/cache',
        ];

        foreach ($paths as $path) {
            $pathName = basename(dirname($path)).'/'.basename($path);

            try {
                $escapedPath = CommandService::escapePath($path);
                // Check if path exists
                $exists = $this->cmd->remote("test -d {$escapedPath} && echo 'EXISTS' || echo 'NOT_FOUND'");

                if (! str_contains($exists, 'EXISTS')) {
                    $this->result->addCheck(
                        'Comprehensive Permission Enforcement',
                        DiagnoseCheck::skip("Writable: {$pathName}", "Path not found: {$path}")
                    );

                    continue;
                }

                // Check if all files/dirs are group-writable
                $output = trim($this->cmd->remote("find {$escapedPath} ! -perm -g+w 2>/dev/null | wc -l"));
                $count = (int) $output;

                if ($count === 0) {
                    $this->result->addCheck(
                        'Comprehensive Permission Enforcement',
                        DiagnoseCheck::pass(
                            "Writable: {$pathName}",
                            'All files/dirs are group-writable',
                            '0 non-writable items'
                        )
                    );
                } else {
                    $this->result->addCheck(
                        'Comprehensive Permission Enforcement',
                        DiagnoseCheck::fail(
                            "Writable: {$pathName}",
                            "{$count} items not group-writable",
                            "{$count} items",
                            '0 items',
                            "sudo chmod -R g+w {$path}"
                        )
                    );
                }
            } catch (\Exception $e) {
                $this->result->addCheck(
                    'Comprehensive Permission Enforcement',
                    DiagnoseCheck::skip("Writable: {$pathName}", 'Could not check')
                );
            }
        }
    }

    // =========================================================================
    // Filesystem Check
    // =========================================================================

    private function checkFilesystem(): void
    {
        try {
            $escapedPath = CommandService::escapePath($this->config->deployPath);
            $output = trim($this->cmd->remote("df -T {$escapedPath} | tail -1 | awk '{print \$2}'"));

            $supportedFs = ['ext4', 'ext3', 'xfs', 'btrfs'];
            $isSupported = in_array(strtolower($output), $supportedFs);

            if ($isSupported) {
                $this->result->addCheck(
                    'Filesystem',
                    DiagnoseCheck::pass(
                        'Filesystem type',
                        "Filesystem: {$output} (supports all permission features)",
                        $output
                    )
                );
            } else {
                $this->result->addCheck(
                    'Filesystem',
                    DiagnoseCheck::warn(
                        'Filesystem type',
                        "Filesystem: {$output} (may have limitations)",
                        $output,
                        'ext4, xfs, or btrfs recommended'
                    )
                );
            }
        } catch (\Exception $e) {
            $this->result->addCheck(
                'Filesystem',
                DiagnoseCheck::skip('Filesystem type', 'Could not check')
            );
        }
    }
}
