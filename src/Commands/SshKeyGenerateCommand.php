<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SshKeyGenerateCommand extends Command
{
    protected $signature = 'deploy:key-generate
                            {email? : Email address for the SSH key}
                            {--name= : Custom name for the key pair (default: id_rsa)}
                            {--force : Force generation of new key pair without prompting}';

    protected $description = 'Generate SSH key pair for deployment and optionally copy to server';

    private string $sshDir;
    private string $defaultKeyPath;

    public function __construct()
    {
        parent::__construct();

        $this->sshDir = $_SERVER['HOME'] . '/.ssh';
        $this->defaultKeyPath = $this->sshDir . '/id_rsa';
    }

    public function handle(): int
    {
        $this->info('🔑 SSH Key Generator for Laravel Deployer');
        $this->line('');

        // Ensure .ssh directory exists
        $this->ensureSshDirectory();

        // Get email (required for key generation)
        $email = $this->argument('email');
        if (!$email) {
            $email = $this->ask('Enter your email address for the SSH key', config('mail.from.address'));
        }

        if (!$email) {
            $this->error('❌ Email address is required for SSH key generation.');
            return self::FAILURE;
        }

        // Check if default key already exists
        if (!$this->option('force') && File::exists($this->defaultKeyPath . '.pub')) {
            return $this->handleExistingKey($email);
        }

        // Generate new key
        return $this->generateNewKey($email);
    }

    protected function ensureSshDirectory(): void
    {
        if (!File::exists($this->sshDir)) {
            File::makeDirectory($this->sshDir, 0700, true);
            $this->info("✅ Created .ssh directory: {$this->sshDir}");
            $this->line('');
        }
    }

    protected function handleExistingKey(string $email): int
    {
        $this->info("SSH key pair ({$this->defaultKeyPath}.pub) already exists.");
        $this->line('');

        $choice = $this->choice(
            'What would you like to do?',
            [
                'show' => 'Show Current Public Key',
                'generate' => 'Generate New Key Pair',
                'copy' => 'Copy Existing Key to Server',
                'cancel' => 'Cancel',
            ],
            'show'
        );

        return match ($choice) {
            'show' => $this->showPublicKey($this->defaultKeyPath . '.pub'),
            'generate' => $this->generateNewKey($email),
            'copy' => $this->copyKeyToServer($this->defaultKeyPath . '.pub'),
            default => self::SUCCESS,
        };
    }

    protected function generateNewKey(string $email): int
    {
        $this->info("Generating a new SSH key pair for email: {$email}");
        $this->line('');

        // Get custom key name if provided
        $keyName = $this->option('name');
        if (!$keyName) {
            $keyName = $this->ask('Enter a name for the new key pair (default is id_rsa)', 'id_rsa');
        }

        $keyPath = $this->sshDir . '/' . $keyName;

        // Check if custom key already exists
        if (File::exists($keyPath)) {
            if (!$this->confirm("Key {$keyName} already exists. Overwrite?", false)) {
                $this->info('ℹ️  Key generation cancelled.');
                return self::SUCCESS;
            }
        }

        // Generate SSH key using ssh-keygen
        $this->info('🔄 Generating SSH key pair...');

        $command = sprintf(
            'ssh-keygen -t rsa -b 4096 -C %s -f %s -N ""',
            escapeshellarg($email),
            escapeshellarg($keyPath)
        );

        $result = Process::run($command);

        if (!$result->successful()) {
            $this->error('❌ Failed to generate SSH key pair:');
            $this->line($result->errorOutput());
            return self::FAILURE;
        }

        $this->line('');
        $this->info('✅ SSH key pair generated successfully!');
        $this->line('');

        // Display the public key
        $this->showPublicKey($keyPath . '.pub');

        // Offer to copy to server
        if ($this->confirm('Would you like to copy this key to a deployment server?', false)) {
            return $this->copyKeyToServer($keyPath . '.pub');
        }

        return self::SUCCESS;
    }

    protected function showPublicKey(string $publicKeyPath): int
    {
        if (!File::exists($publicKeyPath)) {
            $this->error("❌ Public key not found: {$publicKeyPath}");
            return self::FAILURE;
        }

        $publicKey = trim(File::get($publicKeyPath));

        $this->line('');
        $this->info('📋 Your Public SSH Key:');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line($publicKey);
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('');

        $this->info('💡 Next Steps:');
        $this->line('');
        $this->line('   1. Copy the key above to your deployment server');
        $this->line('   2. Add it to ~/.ssh/authorized_keys on the server');
        $this->line('   3. Or use: ssh-copy-id -i ' . $publicKeyPath . ' user@server');
        $this->line('');
        $this->comment('   For GitHub/GitLab/Bitbucket:');
        $this->line('   • Add this key to your repository deploy keys');
        $this->line('   • GitHub: Settings → Deploy keys → Add deploy key');
        $this->line('   • GitLab: Settings → Repository → Deploy keys');
        $this->line('');

        // Offer to copy to clipboard if available
        $this->offerClipboardCopy($publicKey);

        return self::SUCCESS;
    }

    protected function copyKeyToServer(string $publicKeyPath): int
    {
        $this->line('');
        $this->info('📤 Copy SSH Key to Server');
        $this->line('');

        // Load deploy configurations to suggest servers
        $suggestedServers = $this->getSuggestedServers();

        if (!empty($suggestedServers)) {
            $this->info('💡 Available deployment servers from your configuration:');
            foreach ($suggestedServers as $env => $server) {
                $this->line("   • {$env}: {$server['user']}@{$server['hostname']}");
            }
            $this->line('');
        }

        // Get server details
        $hostname = $this->ask('Enter server hostname or IP address');
        if (!$hostname) {
            $this->info('ℹ️  Cancelled.');
            return self::SUCCESS;
        }

        $username = $this->ask('Enter username for the server', 'deploy');
        if (!$username) {
            $this->info('ℹ️  Cancelled.');
            return self::SUCCESS;
        }

        // Attempt to copy key to server
        $this->info("🔄 Copying SSH key to {$username}@{$hostname}...");

        $command = sprintf(
            'ssh-copy-id -i %s %s@%s',
            escapeshellarg($publicKeyPath),
            escapeshellarg($username),
            escapeshellarg($hostname)
        );

        $result = Process::timeout(60)->run($command);

        if ($result->successful()) {
            $this->line('');
            $this->info("✅ SSH key successfully copied to {$username}@{$hostname}!");
            $this->line('');
            $this->info('You can now deploy without password authentication:');
            $this->line("   ssh {$username}@{$hostname}");
            $this->line('');
            return self::SUCCESS;
        }

        // If ssh-copy-id fails, provide manual instructions
        $this->line('');
        $this->warn('⚠️  Automatic copy failed. You can copy manually:');
        $this->line('');

        $publicKey = trim(File::get($publicKeyPath));

        $this->line('1. Connect to your server:');
        $this->line("   ssh {$username}@{$hostname}");
        $this->line('');
        $this->line('2. Run these commands on the server:');
        $this->line('   mkdir -p ~/.ssh');
        $this->line('   chmod 700 ~/.ssh');
        $this->line("   echo '{$publicKey}' >> ~/.ssh/authorized_keys");
        $this->line('   chmod 600 ~/.ssh/authorized_keys');
        $this->line('');

        return self::SUCCESS;
    }

    protected function getSuggestedServers(): array
    {
        $servers = [];
        $deployConfigPath = base_path('.deploy/deploy.yaml');

        if (!File::exists($deployConfigPath)) {
            return $servers;
        }

        try {
            $config = yaml_parse_file($deployConfigPath);

            if (isset($config['hosts']) && is_array($config['hosts'])) {
                foreach ($config['hosts'] as $env => $hostConfig) {
                    if (isset($hostConfig['hostname'])) {
                        $servers[$env] = [
                            'hostname' => $hostConfig['hostname'],
                            'user' => $hostConfig['remote_user'] ?? 'deploy',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail - config parsing is optional
        }

        return $servers;
    }

    protected function offerClipboardCopy(string $publicKey): void
    {
        // Check if clipboard command is available
        $clipboardCmd = null;

        if (PHP_OS_FAMILY === 'Linux') {
            // Try xclip first, then xsel
            $result = Process::run('which xclip 2>/dev/null');
            if ($result->successful()) {
                $clipboardCmd = 'xclip -selection clipboard';
            } else {
                $result = Process::run('which xsel 2>/dev/null');
                if ($result->successful()) {
                    $clipboardCmd = 'xsel --clipboard --input';
                }
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            // macOS
            $clipboardCmd = 'pbcopy';
        } elseif (PHP_OS_FAMILY === 'Windows') {
            // Windows
            $clipboardCmd = 'clip';
        }

        if ($clipboardCmd && $this->confirm('Copy public key to clipboard?', false)) {
            $result = Process::run("echo " . escapeshellarg($publicKey) . " | {$clipboardCmd}");

            if ($result->successful()) {
                $this->info('✅ Public key copied to clipboard!');
            } else {
                $this->warn('⚠️  Could not copy to clipboard. Please copy manually.');
            }
        }
    }
}
