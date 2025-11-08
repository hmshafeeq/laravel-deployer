<?php

namespace Shaf\LaravelDeployer\Deployer;

class DeploymentTasks
{
    protected Deployer $deployer;

    public function __construct(Deployer $deployer)
    {
        $this->deployer = $deployer;
    }

    public function deployInfo(): void
    {
        $this->deployer->task('deploy:info', function ($deployer) {
            $user = $deployer->runLocally('git config --get user.name', false);
            $branch = $deployer->get('branch', 'HEAD');
            $releaseName = $deployer->getReleaseName();

            $deployer->writeln("info deploying something to {$deployer->get('hostname')} (release {$releaseName})");
        });
    }

    public function setup(): void
    {
        $this->deployer->task('deploy:setup', function ($deployer) {
            $deployPath = $deployer->getDeployPath();

            $deployer->writeln("run [ -d {$deployPath} ] || mkdir -p {$deployPath};");
            $deployer->run("[ -d {$deployPath} ] || mkdir -p {$deployPath}");

            $deployer->writeln("run cd {$deployPath};");
            $deployer->run("cd {$deployPath}");

            $deployer->writeln("run [ -d .dep ] || mkdir .dep;");
            $deployer->run("cd {$deployPath} && [ -d .dep ] || mkdir .dep");

            $deployer->writeln("run [ -d releases ] || mkdir releases;");
            $deployer->run("cd {$deployPath} && [ -d releases ] || mkdir releases");

            $deployer->writeln("run [ -d shared ] || mkdir shared;");
            $deployer->run("cd {$deployPath} && [ -d shared ] || mkdir shared");

            // Check if current exists and is not a symlink
            $deployer->writeln("run if [ ! -L {$deployPath}/current ] && [ -d {$deployPath}/current ]; then echo +appropriate; fi");
            $result = $deployer->run("if [ ! -L {$deployPath}/current ] && [ -d {$deployPath}/current ]; then echo +appropriate; fi");
            if (!empty($result)) {
                $deployer->writeln($result);
            }
        });
    }

    public function checkLock(): void
    {
        $this->deployer->task('deploy:check-lock', function ($deployer) {
            $lockFile = $deployer->getDeployPath() . '/.dep/deploy.lock';

            $deployer->writeln("run if [ -f {$lockFile} ]; then echo +legitimate; fi");
            $exists = $deployer->run("if [ -f {$lockFile} ]; then echo +legitimate; fi");

            if (!empty($exists)) {
                $deployer->writeln($exists);
                throw new \RuntimeException("Deployment is locked");
            }
        });
    }

    public function lock(): void
    {
        $this->deployer->task('deploy:lock', function ($deployer) {
            $user = $deployer->runLocally('git config --get user.name');
            $deployer->writeln("run git config --get user.name");
            $deployer->writeln($user);

            $lockFile = $deployer->getDeployPath() . '/.dep/deploy.lock';
            $deployer->writeln("run [ -f {$lockFile} ] && echo +locked || echo '{$user}' > {$lockFile}");
            $result = $deployer->run("[ -f {$lockFile} ] && echo +locked || echo '{$user}' > {$lockFile}");
            if (!empty($result)) {
                $deployer->writeln($result);
            }
        });
    }

    public function unlock(): void
    {
        $this->deployer->task('deploy:unlock', function ($deployer) {
            $lockFile = $deployer->getDeployPath() . '/.dep/deploy.lock';
            $deployer->writeln("run rm -f {$lockFile}");
            $deployer->run("rm -f {$lockFile}");
        });
    }

    public function release(): void
    {
        $this->deployer->task('deploy:release', function ($deployer) {
            $deployPath = $deployer->getDeployPath();
            $releaseName = $deployer->getReleaseName();

            // Check if release symlink exists
            $deployer->writeln("run cd {$deployPath} && (if [ -h release ]; then echo +yes; fi)");
            $result = $deployer->run("cd {$deployPath} && (if [ -h release ]; then echo +yes; fi)");
            if (!empty($result)) {
                $deployer->writeln($result);
            }

            // Check if releases directory has content
            $deployer->writeln("run cd {$deployPath} && (if [ -d releases ] && [ \"$(ls -A releases)\" ]; then echo +true; fi)");
            $hasReleases = $deployer->run("cd {$deployPath} && (if [ -d releases ] && [ \"$(ls -A releases)\" ]; then echo +true; fi)");
            if (!empty($hasReleases)) {
                $deployer->writeln($hasReleases);

                // List existing releases
                $deployer->writeln("run cd {$deployPath} && (cd releases && ls -t -1 -d */)");
                $releases = $deployer->run("cd {$deployPath} && (cd releases && ls -t -1 -d */)");
                if (!empty($releases)) {
                    $lines = explode("\n", trim($releases));
                    foreach ($lines as $line) {
                        $deployer->writeln($line);
                    }
                }
            }

            // Check if releases_log exists
            $deployer->writeln("run cd {$deployPath} && (if [ -f .dep/releases_log ]; then echo +true; fi)");
            $hasLog = $deployer->run("cd {$deployPath} && (if [ -f .dep/releases_log ]; then echo +true; fi)");
            if (!empty($hasLog)) {
                $deployer->writeln($hasLog);

                // Show last releases from log
                $deployer->writeln("run cd {$deployPath} && (tail -n 300 .dep/releases_log)");
                $log = $deployer->run("cd {$deployPath} && (tail -n 300 .dep/releases_log)");
                if (!empty($log)) {
                    $lines = explode("\n", trim($log));
                    foreach ($lines as $line) {
                        $deployer->writeln($line);
                    }
                }
            }

            // Check if release directory exists
            $deployer->writeln("run cd {$deployPath} && (if [ -d releases/{$releaseName} ]; then echo +correct; fi)");
            $deployer->run("cd {$deployPath} && (if [ -d releases/{$releaseName} ]; then echo +correct; fi)");

            // Write to latest_release
            $deployer->writeln("run cd {$deployPath} && (echo {$releaseName} > .dep/latest_release)");
            $deployer->run("cd {$deployPath} && (echo {$releaseName} > .dep/latest_release)");

            // Log the release
            $timestamp = date('Y-m-d\TH:i:s+0000');
            $user = $deployer->runLocally('git config --get user.name');
            $branch = $deployer->get('branch', 'HEAD');
            $logEntry = json_encode([
                'created_at' => $timestamp,
                'release_name' => $releaseName,
                'user' => $user,
                'target' => $branch
            ]);

            $deployer->writeln("run cd {$deployPath} && (echo '{$logEntry}' >> .dep/releases_log)");
            $deployer->run("cd {$deployPath} && (echo '{$logEntry}' >> .dep/releases_log)");

            // Create release directory
            $deployer->writeln("run cd {$deployPath} && (mkdir -p releases/{$releaseName})");
            $deployer->run("cd {$deployPath} && (mkdir -p releases/{$releaseName})");

            // Check if ln supports --relative
            $deployer->writeln("run cd {$deployPath} && ((man ln 2>&1 || ln -h 2>&1 || ln --help 2>&1) | grep -- --relative || true)");
            $supportsRelative = $deployer->run("cd {$deployPath} && ((man ln 2>&1 || ln -h 2>&1 || ln --help 2>&1) | grep -- --relative || true)");
            if (!empty($supportsRelative)) {
                $deployer->writeln("       -r, --relative");
            }

            // Create release symlink
            $deployer->writeln("run cd {$deployPath} && (ln -nfs --relative releases/{$releaseName} {$deployPath}/release)");
            $deployer->run("cd {$deployPath} && (ln -nfs --relative releases/{$releaseName} {$deployPath}/release)");
        });
    }

    public function buildAssets(): void
    {
        $this->deployer->task('build:assets', function ($deployer) {
            $deployer->runLocalCommand('npm run build');
        });
    }

    public function rsync(): void
    {
        $this->deployer->task('rsync', function ($deployer) {
            $deployPath = $deployer->getDeployPath();
            $releaseName = $deployer->getReleaseName();

            // Check if release symlink exists
            $deployer->writeln("run if [ -h {$deployPath}/release ]; then echo +precise; fi");
            $result = $deployer->run("if [ -h {$deployPath}/release ]; then echo +precise; fi");
            if (!empty($result)) {
                $deployer->writeln($result);
            }

            // Read the symlink
            $deployer->writeln("run readlink {$deployPath}/release");
            $link = $deployer->run("readlink {$deployPath}/release");
            $deployer->writeln($link);

            // Run rsync
            $deployer->runRsync();
        });
    }

    public function shared(): void
    {
        $this->deployer->task('deploy:shared', function ($deployer) {
            $deployPath = $deployer->getDeployPath();
            $releasePath = $deployer->getReleasePath();
            $sharedPath = $deployer->getSharedPath();

            // Link storage
            $deployer->writeln("run if [ -d {$sharedPath}/storage ]; then echo +indeed; fi");
            $storageExists = $deployer->run("if [ -d {$sharedPath}/storage ]; then echo +indeed; fi");
            if (!empty($storageExists)) {
                $deployer->writeln($storageExists);
            }

            $deployer->writeln("run rm -rf {$releasePath}/storage");
            $deployer->run("rm -rf {$releasePath}/storage");

            $deployer->writeln("run mkdir -p `dirname {$releasePath}/storage`");
            $deployer->run("mkdir -p `dirname {$releasePath}/storage`");

            $deployer->writeln("run ln -nfs --relative {$sharedPath}/storage {$releasePath}/storage");
            $deployer->run("ln -nfs --relative {$sharedPath}/storage {$releasePath}/storage");

            // Link .env
            $deployer->writeln("run if [ -d {$sharedPath}/. ]; then echo +correct; fi");
            $result = $deployer->run("if [ -d {$sharedPath}/. ]; then echo +correct; fi");
            if (!empty($result)) {
                $deployer->writeln($result);
            }

            $deployer->writeln("run if [ -f {$sharedPath}/.env ]; then echo +accurate; fi");
            $envExists = $deployer->run("if [ -f {$sharedPath}/.env ]; then echo +accurate; fi");
            if (!empty($envExists)) {
                $deployer->writeln($envExists);
            }

            $deployer->writeln("run if [ -f $(echo {$releasePath}/.env) ]; then rm -rf {$releasePath}/.env; fi");
            $deployer->run("if [ -f $(echo {$releasePath}/.env) ]; then rm -rf {$releasePath}/.env; fi");

            $deployer->writeln("run if [ ! -d $(echo {$releasePath}/.) ]; then mkdir -p {$releasePath}/.;fi");
            $deployer->run("if [ ! -d $(echo {$releasePath}/.) ]; then mkdir -p {$releasePath}/.;fi");

            $deployer->writeln("run [ -f {$sharedPath}/.env ] || touch {$sharedPath}/.env");
            $deployer->run("[ -f {$sharedPath}/.env ] || touch {$sharedPath}/.env");

            $deployer->writeln("run ln -nfs --relative {$sharedPath}/.env {$releasePath}/.env");
            $deployer->run("ln -nfs --relative {$sharedPath}/.env {$releasePath}/.env");
        });
    }

    public function writable(): void
    {
        $this->deployer->task('deploy:writable', function ($deployer) {
            $releasePath = $deployer->getReleasePath();

            // Check if release symlink exists
            $deployer->writeln("run if [ -h {$deployer->getDeployPath()}/release ]; then echo +correct; fi");
            $result = $deployer->run("if [ -h {$deployer->getDeployPath()}/release ]; then echo +correct; fi");
            if (!empty($result)) {
                $deployer->writeln($result);
            }

            // Create writable directories
            $dirs = [
                'bootstrap/cache',
                'storage',
                'storage/app',
                'storage/app/public',
                'storage/framework',
                'storage/framework/cache',
                'storage/framework/cache/data',
                'storage/framework/sessions',
                'storage/framework/views',
                'storage/logs'
            ];

            $dirList = implode(' ', $dirs);
            $deployer->writeln("run cd {$releasePath} && (mkdir -p {$dirList})");
            $deployer->run("cd {$releasePath} && (mkdir -p {$dirList})");

            // Detect web server user
            $deployer->writeln("run cd {$releasePath} && (ps axo comm,user | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | sort | awk '{print \$NF}' | uniq)");
            $httpUser = $deployer->run("cd {$releasePath} && (ps axo comm,user | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | sort | awk '{print \$NF}' | uniq)");
            if (!empty($httpUser)) {
                $deployer->writeln($httpUser);
            }

            // Check if chmod supports specific format
            $deployer->writeln("run cd {$releasePath} && (chmod 2>&1; true)");
            $chmodResult = $deployer->run("cd {$releasePath} && (chmod 2>&1; true)");
            if (!empty($chmodResult)) {
                $lines = explode("\n", trim($chmodResult));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }

            // Check if setfacl is available
            $deployer->writeln("run cd {$releasePath} && (if hash setfacl 2>/dev/null; then echo +true; fi)");
            $hasSetfacl = $deployer->run("cd {$releasePath} && (if hash setfacl 2>/dev/null; then echo +true; fi)");
            if (!empty($hasSetfacl)) {
                $deployer->writeln($hasSetfacl);

                // Get current user
                $currentUser = $deployer->get('remote_user');
                $deployer->writeln("run cd {$releasePath} && (if id -u {$currentUser} &>/dev/null 2>&1 || exit 0; then echo +right; fi)");
                $userExists = $deployer->run("cd {$releasePath} && (if id -u {$currentUser} &>/dev/null 2>&1 || exit 0; then echo +right; fi)");
                if (!empty($userExists)) {
                    $deployer->writeln($userExists);
                }

                // Set ACLs for bootstrap/cache
                $deployer->writeln("run cd {$releasePath} && (getfacl -p bootstrap/cache | grep \"^user:{$httpUser}:.*w\" | wc -l)");
                $aclCount = $deployer->run("cd {$releasePath} && (getfacl -p bootstrap/cache | grep \"^user:{$httpUser}:.*w\" | wc -l)");
                if (!empty($aclCount) && trim($aclCount) !== '0') {
                    $deployer->writeln($aclCount);
                } else {
                    $deployer->writeln("run cd {$releasePath} && (setfacl -L  -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX bootstrap/cache)");
                    $deployer->run("cd {$releasePath} && (setfacl -L  -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX bootstrap/cache)");

                    $deployer->writeln("run cd {$releasePath} && (setfacl -dL  -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX bootstrap/cache)");
                    $deployer->run("cd {$releasePath} && (setfacl -dL  -m u:\"{$httpUser}\":rwX -m u:{$currentUser}:rwX bootstrap/cache)");
                }

                // Check ACLs for storage directories
                foreach (['storage', 'storage/app', 'storage/app/public', 'storage/framework', 'storage/framework/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $dir) {
                    $deployer->writeln("run cd {$releasePath} && (getfacl -p {$dir} | grep \"^user:{$httpUser}:.*w\" | wc -l)");
                    $aclCount = $deployer->run("cd {$releasePath} && (getfacl -p {$dir} | grep \"^user:{$httpUser}:.*w\" | wc -l)");
                    if (!empty($aclCount)) {
                        $deployer->writeln($aclCount);
                    }
                }
            }
        });
    }

    public function vendors(): void
    {
        $this->deployer->task('deploy:vendors', function ($deployer) {
            $releasePath = $deployer->getReleasePath();
            $composerOptions = $deployer->get('composer_options', '--verbose --prefer-dist --no-interaction --no-scripts --optimize-autoloader');

            // Check if unzip is available
            $deployer->writeln("run if hash unzip 2>/dev/null; then echo +accurate; fi");
            $hasUnzip = $deployer->run("if hash unzip 2>/dev/null; then echo +accurate; fi");
            if (!empty($hasUnzip)) {
                $deployer->writeln($hasUnzip);
            }

            // Check for composer.phar
            $deployer->writeln("run if [ -f {$deployer->getDeployPath()}/.dep/composer.phar ]; then echo +true; fi");
            $deployer->run("if [ -f {$deployer->getDeployPath()}/.dep/composer.phar ]; then echo +true; fi");

            // Check if composer command is available
            $deployer->writeln("run if hash composer 2>/dev/null; then echo +indeed; fi");
            $hasComposer = $deployer->run("if hash composer 2>/dev/null; then echo +indeed; fi");
            if (!empty($hasComposer)) {
                $deployer->writeln($hasComposer);
            }

            // Get composer path
            $deployer->writeln("run command -v 'composer' || which 'composer' || type -p 'composer'");
            $composerPath = $deployer->run("command -v 'composer' || which 'composer' || type -p 'composer'");
            $deployer->writeln($composerPath);

            // Get PHP path
            $deployer->writeln("run command -v 'php' || which 'php' || type -p 'php'");
            $phpPath = $deployer->run("command -v 'php' || which 'php' || type -p 'php'");
            $deployer->writeln($phpPath);

            // Run composer install
            $composerCommand = "cd {$releasePath} && {$phpPath} {$composerPath} install {$composerOptions} 2>&1";
            $deployer->writeln("run {$composerCommand}");

            $result = $deployer->run($composerCommand);
            if (!empty($result)) {
                $lines = explode("\n", $result);
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }
        });
    }

    public function fixModulePermissions(): void
    {
        $this->deployer->task('deploy:fix-module-permissions', function ($deployer) {
            $releasePath = $deployer->getReleasePath();

            $deployer->writeln("🔒 Fixing permissions for modular structure...");

            $deployer->writeln("run chmod -R 755 {$releasePath}/app-modules || true");
            $deployer->run("chmod -R 755 {$releasePath}/app-modules || true");

            $deployer->writeln("run find {$releasePath}/app-modules -type f -exec chmod 644 {} \\; || true");
            $deployer->run("find {$releasePath}/app-modules -type f -exec chmod 644 {} \\; || true");

            $deployer->writeln("run find {$releasePath}/app-modules -type d -exec chmod 755 {} \\; || true");
            $deployer->run("find {$releasePath}/app-modules -type d -exec chmod 755 {} \\; || true");

            $deployer->writeln("✅ Module permissions fixed");
        });
    }

    public function symlink(): void
    {
        $this->deployer->task('deploy:symlink', function ($deployer) {
            $deployPath = $deployer->getDeployPath();

            $deployer->writeln("run (man mv 2>&1 || mv -h 2>&1 || mv --help 2>&1) | grep -- --no-target-directory || true");
            $supportsNoTarget = $deployer->run("(man mv 2>&1 || mv -h 2>&1 || mv --help 2>&1) | grep -- --no-target-directory || true");
            if (!empty($supportsNoTarget)) {
                $deployer->writeln("       -T, --no-target-directory");
            }

            $deployer->writeln("run mv -T {$deployPath}/release {$deployPath}/current");
            $deployer->run("mv -T {$deployPath}/release {$deployPath}/current");
        });
    }

    public function cleanup(): void
    {
        $this->deployer->task('deploy:cleanup', function ($deployer) {
            $deployPath = $deployer->getDeployPath();
            $keepReleases = $deployer->get('keep_releases', 3);

            // Remove release symlink
            $deployer->writeln("run cd {$deployPath} && if [ -e release ]; then rm release; fi");
            $deployer->run("cd {$deployPath} && if [ -e release ]; then rm release; fi");

            // Get list of releases sorted by time, keep only the specified number
            $releases = $deployer->run("cd {$deployPath}/releases && ls -t -1 -d */ | tail -n +".($keepReleases + 1));
            if (!empty($releases)) {
                $releasesToDelete = explode("\n", trim($releases));
                foreach ($releasesToDelete as $release) {
                    $release = trim($release, '/');
                    $deployer->writeln("run  rm -rf {$deployPath}/releases/{$release}");
                    $deployer->run("rm -rf {$deployPath}/releases/{$release}");
                }
            }
        });
    }

    public function success(): void
    {
        $this->deployer->task('deploy:success', function ($deployer) {
            $deployer->writeln("info successfully deployed!");
        });
    }

    public function linkDep(): void
    {
        $this->deployer->task('deploy:link-dep', function ($deployer) {
            $deployPath = $deployer->getDeployPath();
            $sharedPath = $deployer->getSharedPath();

            $deployer->writeln("run ln -sf {$deployPath}/.dep {$sharedPath}/storage/app/deployment");
            $deployer->run("ln -sf {$deployPath}/.dep {$sharedPath}/storage/app/deployment");
        });
    }

    public function postDeployment(): void
    {
        $this->deployer->task('post:deployment', function ($deployer) {
            $currentPath = $deployer->getCurrentPath();

            // Publish log viewer assets
            $deployer->writeln("run cd {$currentPath} && /usr/bin/php artisan vendor:publish --tag=log-viewer-assets --force");
            $result = $deployer->run("cd {$currentPath} && /usr/bin/php artisan vendor:publish --tag=log-viewer-assets --force");
            if (!empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }

            // Run post-deployment script if it exists
            $deployer->writeln("run cd {$currentPath} && ./post-deployment.sh");
            $result = $deployer->run("cd {$currentPath} && ./post-deployment.sh");
            if (!empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }
        });
    }

    // Artisan tasks
    public function artisanStorageLink(): void
    {
        $this->deployer->task('artisan:storage:link', function ($deployer) {
            $releasePath = $deployer->getReleasePath();
            $phpPath = "/usr/bin/php";

            $deployer->writeln("run {$phpPath} {$releasePath}/artisan --version");
            $version = $deployer->run("{$phpPath} {$releasePath}/artisan --version");
            $deployer->writeln($version);

            $deployer->writeln("run {$phpPath} {$releasePath}/artisan storage:link");
            $result = $deployer->run("{$phpPath} {$releasePath}/artisan storage:link");
            if (!empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }
        });
    }

    public function artisanConfigCache(): void
    {
        $this->deployer->task('artisan:config:cache', function ($deployer) {
            $releasePath = $deployer->getReleasePath();
            $phpPath = "/usr/bin/php";

            $deployer->writeln("run {$phpPath} {$releasePath}/artisan config:cache");
            $result = $deployer->run("{$phpPath} {$releasePath}/artisan config:cache");
            if (!empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }
        });
    }

    public function artisanViewCache(): void
    {
        $this->deployer->task('artisan:view:cache', function ($deployer) {
            $releasePath = $deployer->getReleasePath();
            $phpPath = "/usr/bin/php";

            $deployer->writeln("run {$phpPath} {$releasePath}/artisan view:cache");
            $result = $deployer->run("{$phpPath} {$releasePath}/artisan view:cache");
            if (!empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }
        });
    }

    public function artisanRouteCache(): void
    {
        $this->deployer->task('artisan:route:cache', function ($deployer) {
            $releasePath = $deployer->getReleasePath();
            $phpPath = "/usr/bin/php";

            $deployer->writeln("run {$phpPath} {$releasePath}/artisan route:cache");
            $result = $deployer->run("{$phpPath} {$releasePath}/artisan route:cache");
            if (!empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }
        });
    }

    public function artisanOptimize(): void
    {
        $this->deployer->task('artisan:optimize', function ($deployer) {
            $releasePath = $deployer->getReleasePath();
            $phpPath = "/usr/bin/php";

            $deployer->writeln("run {$phpPath} {$releasePath}/artisan optimize");
            $result = $deployer->run("{$phpPath} {$releasePath}/artisan optimize");
            if (!empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }
        });
    }

    public function artisanMigrate(): void
    {
        $this->deployer->task('artisan:migrate', function ($deployer) {
            $releasePath = $deployer->getReleasePath();
            $phpPath = "/usr/bin/php";

            $deployer->writeln("run if [ -s {$releasePath}/.env ]; then echo +accurate; fi");
            $hasEnv = $deployer->run("if [ -s {$releasePath}/.env ]; then echo +accurate; fi");
            if (!empty($hasEnv)) {
                $deployer->writeln($hasEnv);
            }

            $deployer->writeln("run {$phpPath} {$releasePath}/artisan migrate --force");
            $result = $deployer->run("{$phpPath} {$releasePath}/artisan migrate --force");
            if (!empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }
        });
    }

    public function artisanQueueRestart(): void
    {
        $this->deployer->task('artisan:queue:restart', function ($deployer) {
            $releasePath = $deployer->getReleasePath();
            $phpPath = "/usr/bin/php";

            $deployer->writeln("run {$phpPath} {$releasePath}/artisan queue:restart");
            $result = $deployer->run("{$phpPath} {$releasePath}/artisan queue:restart");
            if (!empty($result)) {
                $lines = explode("\n", trim($result));
                foreach ($lines as $line) {
                    $deployer->writeln($line);
                }
            }
        });
    }

    /**
     * Get list of all releases sorted by time (newest first)
     */
    public function getReleases(): array
    {
        $deployPath = $this->deployer->getDeployPath();
        $releasesPath = "{$deployPath}/releases";

        // Check if releases directory exists
        $exists = $this->deployer->test("[ -d {$releasesPath} ]");
        if (!$exists) {
            return [];
        }

        // Get list of releases sorted by time (newest first)
        $output = $this->deployer->run("cd {$releasesPath} && ls -t -1 2>/dev/null || echo ''");

        if (empty(trim($output))) {
            return [];
        }

        $releases = array_filter(explode("\n", trim($output)));

        return array_values($releases);
    }

    /**
     * Get the current release name
     */
    public function getCurrentRelease(): ?string
    {
        $deployPath = $this->deployer->getDeployPath();
        $currentPath = "{$deployPath}/current";

        // Check if current symlink exists
        $exists = $this->deployer->test("[ -L {$currentPath} ]");
        if (!$exists) {
            return null;
        }

        // Get the release name from the symlink
        $output = $this->deployer->run("basename \$(readlink -f {$currentPath}) 2>/dev/null || echo ''");

        if (empty(trim($output))) {
            return null;
        }

        return trim($output);
    }

    /**
     * Rollback to a specific release
     */
    public function rollback(string $targetRelease): void
    {
        $deployPath = $this->deployer->getDeployPath();
        $releasesPath = "{$deployPath}/releases";
        $targetPath = "{$releasesPath}/{$targetRelease}";
        $currentPath = "{$deployPath}/current";

        $this->deployer->writeln("🔄 Rolling back to release: {$targetRelease}", 'info');

        // Verify target release exists
        $exists = $this->deployer->test("[ -d {$targetPath} ]");
        if (!$exists) {
            throw new \RuntimeException("Release {$targetRelease} does not exist");
        }

        // Create release symlink
        $this->deployer->writeln("run ln -nfs {$targetPath} {$deployPath}/release");
        $this->deployer->run("ln -nfs {$targetPath} {$deployPath}/release");

        // Atomic swap to new release
        $this->deployer->writeln("run mv -fT {$deployPath}/release {$currentPath}");
        $this->deployer->run("mv -fT {$deployPath}/release {$currentPath}");

        $this->deployer->writeln("✓ Symlink updated to: {$targetRelease}", 'info');
    }

    /**
     * Get rollback information
     */
    public function getRollbackInfo(): array
    {
        $releases = $this->getReleases();
        $currentRelease = $this->getCurrentRelease();

        $info = [
            'current' => $currentRelease,
            'releases' => $releases,
            'can_rollback' => false,
            'previous' => null,
        ];

        if ($currentRelease && count($releases) > 1) {
            $currentIndex = array_search($currentRelease, $releases);
            if ($currentIndex !== false && $currentIndex < count($releases) - 1) {
                $info['can_rollback'] = true;
                $info['previous'] = $releases[$currentIndex + 1];
            }
        }

        return $info;
    }
}
