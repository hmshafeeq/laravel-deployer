# SSH Key Generate Command

Interactive SSH key generator for deployment setup.

## Command

```bash
php artisan deploy:key-generate {email?}
```

## Arguments

- `email` (optional) - Email address for the SSH key

## Options

- `--name=KEY_NAME` - Custom name for the key pair (default: `id_rsa`)
- `--force` - Force generation of new key pair without prompting

## Usage Examples

```bash
# Interactive generation
php artisan deploy:key-generate deploy@yourapp.com

# Custom key name
php artisan deploy:key-generate deploy@yourapp.com --name=deploy_key

# Force new key without prompting
php artisan deploy:key-generate deploy@yourapp.com --force

# Prompt for email
php artisan deploy:key-generate
```

## What It Does

The SSH key generator:

1. **Detects Existing Keys** - Checks if SSH keys already exist
2. **Interactive Menu** - Shows options if keys exist
3. **Generates Key Pair** - Creates RSA 4096-bit keys
4. **Displays Public Key** - Shows key for copying
5. **Suggests Servers** - Lists servers from deploy.json
6. **Copies to Server** - Uses `ssh-copy-id` to deploy key
7. **Provides Instructions** - GitHub/GitLab/Bitbucket setup guides
8. **Clipboard Support** - Copies key to clipboard (if available)

## Interactive Workflow

### When Keys Already Exist

```bash
$ php artisan deploy:key-generate deploy@yourapp.com

SSH key pair (/home/user/.ssh/id_rsa.pub) already exists.

What would you like to do?
  [show] Show Current Public Key
  [generate] Generate New Key Pair
  [copy] Copy Existing Key to Server
  [cancel] Cancel

> generate
```

### Generating New Key

```bash
Generating a new SSH key pair for email: deploy@yourapp.com

Enter a name for the new key pair (default is id_rsa): deploy_key

🔄 Generating SSH key pair...
✅ SSH key pair generated successfully!

📋 Your Public SSH Key:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAACAQC... deploy@yourapp.com
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

💡 Next Steps:
   1. Copy the key above to your deployment server
   2. Add it to ~/.ssh/authorized_keys on the server
   3. Or use: ssh-copy-id -i /home/user/.ssh/deploy_key.pub user@server

Would you like to copy this key to a deployment server? (yes/no) [no]:
```

### Copying to Server

```bash
📤 Copy SSH Key to Server

💡 Available deployment servers from your configuration:
   • staging: deploy@staging.yourapp.com
   • production: deploy@yourapp.com

Enter server hostname or IP address: staging.yourapp.com

Enter username for the server [deploy]: deploy

🔄 Copying SSH key to deploy@staging.yourapp.com...

✅ SSH key successfully copied to deploy@staging.yourapp.com!

You can now deploy without password authentication:
   ssh deploy@staging.yourapp.com
```

## Key Generation Details

### Algorithm
- **Type**: RSA
- **Bits**: 4096
- **Format**: OpenSSH format
- **Comment**: Your email address

### Generated Files
```
~/.ssh/
├── id_rsa           ← Private key (keep secret!)
└── id_rsa.pub       ← Public key (share this)
```

Or with custom name:
```
~/.ssh/
├── deploy_key       ← Private key
└── deploy_key.pub   ← Public key
```

### Permissions
- Private key: `600` (read/write for owner only)
- Public key: `644` (readable by all)
- `.ssh` directory: `700` (accessible by owner only)

## Server Discovery

The command reads `.deploy/deploy.json` to suggest servers:

```yaml
hosts:
  staging:
    hostname: staging.yourapp.com
    remote_user: deploy

  production:
    hostname: yourapp.com
    remote_user: deploy
```

These are automatically suggested when copying keys to servers.

## Clipboard Support

The command can copy public keys to clipboard:

### Linux
Requires: `xclip` or `xsel`
```bash
# Install on Ubuntu/Debian
sudo apt-get install xclip

# Install on Fedora/RHEL
sudo dnf install xclip
```

### macOS
Built-in support via `pbcopy`

### Windows
Built-in support via `clip`

## Use Cases

### Initial Deployment Setup
```bash
# 1. Generate key
php artisan deploy:key-generate deploy@myapp.com

# 2. Copy to staging server
# (Follow interactive prompts)

# 3. Test connection
ssh deploy@staging.myapp.com
```

### Multiple Servers
```bash
# Generate key once
php artisan deploy:key-generate deploy@myapp.com --name=deploy_key

# Copy to multiple servers
ssh-copy-id -i ~/.ssh/deploy_key.pub deploy@staging.com
ssh-copy-id -i ~/.ssh/deploy_key.pub deploy@production.com
```

### CI/CD Setup
```bash
# Generate key for CI/CD
php artisan deploy:key-generate ci@myapp.com --name=ci_deploy_key

# Add public key to server
# Add private key to CI/CD secrets
```

### Team Member Onboarding
```bash
# New developer generates key
php artisan deploy:key-generate developer@myapp.com

# Copy to development server
# (Follow interactive prompts)
```

## GitHub/GitLab/Bitbucket Setup

### GitHub
1. Copy public key
2. Go to: Repository → Settings → Deploy keys
3. Click "Add deploy key"
4. Paste key and save

### GitLab
1. Copy public key
2. Go to: Repository → Settings → Repository → Deploy keys
3. Click "Add key"
4. Paste key and save

### Bitbucket
1. Copy public key
2. Go to: Repository → Settings → Access keys
3. Click "Add key"
4. Paste key and save

## Manual Key Copy

If automatic copy fails, manual instructions are provided:

```bash
# 1. Connect to server
ssh user@server.com

# 2. Run these commands on server
mkdir -p ~/.ssh
chmod 700 ~/.ssh
echo 'ssh-rsa AAAAB3Nza...' >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

## Error Handling

Common errors:

### SSH Key Generation Failed
```
❌ Failed to generate SSH key pair
```
**Fix**: Ensure `ssh-keygen` is installed

### ssh-copy-id Not Found
```
command not found: ssh-copy-id
```
**Fix**: Use manual copy instructions provided

### Permission Denied
```
Permission denied when copying key
```
**Fix**: Verify server credentials, use password authentication

### Key Already Exists
```
Key deploy_key already exists. Overwrite? (yes/no)
```
**Fix**: Choose different name or confirm overwrite

## Security Best Practices

### Key Protection
- ✅ Never share your private key
- ✅ Use strong passphrases (optional)
- ✅ Store private keys securely
- ✅ Use separate keys for different purposes
- ✅ Rotate keys regularly

### Server Security
- ✅ Disable password authentication after key setup
- ✅ Use different keys for different environments
- ✅ Limit key access with SSH config
- ✅ Audit authorized_keys regularly

### SSH Config

Add to `~/.ssh/config`:

```
Host staging
    HostName staging.yourapp.com
    User deploy
    IdentityFile ~/.ssh/deploy_key

Host production
    HostName yourapp.com
    User deploy
    IdentityFile ~/.ssh/deploy_key
```

Then connect with: `ssh staging`

## Related Commands

- [`deploy`](deploy.md) - Deploy application (uses SSH keys)
- [`deploy:rollback`](rollback.md) - Rollback deployment
- [`laravel-deployer:install`](install.md) - Initial package setup

## Tips

- **Generate once, use everywhere** - One key per developer
- **Use descriptive names** for multiple keys
- **Test connection** after adding key to server
- **Document key locations** for team members
- **Backup private keys** securely
- **Remove keys** when team members leave

## Architecture

This command is standalone and provides interactive SSH key management:
- Detects existing keys in `~/.ssh/`
- Generates RSA 4096-bit keys using `ssh-keygen`
- Copies keys using `ssh-copy-id`
- Supports clipboard operations (platform-dependent)

See: `src/Commands/SshKeyGenerateCommand.php`
