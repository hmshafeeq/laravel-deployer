#!/bin/bash

################################################################################
# User Management Functions
################################################################################

# Create deployment user
create_deploy_user() {
    local username=$1
    local password=$2

    print_info "Creating user: $username"

    # Check if user already exists
    if id "$username" >/dev/null 2>&1; then
        print_warning "User $username already exists"
        return 0
    fi

    # Create user with home directory
    useradd -m -s /bin/bash "$username"
    print_success "User $username created"

    # Set password if provided
    if [ -n "$password" ]; then
        echo "$username:$password" | chpasswd
        print_success "Password set for $username"
    fi

    # Add user to sudo group
    usermod -aG sudo "$username"
    print_success "Added $username to sudo group"

    # Configure passwordless sudo
    echo "$username ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/$username"
    chmod 0440 "/etc/sudoers.d/$username"
    print_success "Configured passwordless sudo for $username"

    # Setup SSH directory
    local ssh_dir="/home/$username/.ssh"
    mkdir -p "$ssh_dir"
    chmod 700 "$ssh_dir"

    # Generate SSH key pair
    print_info "Generating SSH key pair..."
    ssh-keygen -t rsa -b 4096 -f "$ssh_dir/id_rsa" -N "" -C "$username@$(hostname)" >/dev/null 2>&1

    # Add public key to authorized_keys
    cat "$ssh_dir/id_rsa.pub" >> "$ssh_dir/authorized_keys"
    chmod 600 "$ssh_dir/authorized_keys"

    print_success "SSH key pair generated"

    # Set proper ownership
    chown -R "$username:$username" "$ssh_dir"

    # Create common directories
    local directories=(
        "/home/$username/sites"
        "/home/$username/logs"
        "/home/$username/.config"
    )

    for dir in "${directories[@]}"; do
        mkdir -p "$dir"
        chown "$username:$username" "$dir"
    done

    print_success "User directories created"

    # Copy root's authorized_keys if exists (for initial access)
    if [ -f "/root/.ssh/authorized_keys" ]; then
        cat "/root/.ssh/authorized_keys" >> "$ssh_dir/authorized_keys"
        chown "$username:$username" "$ssh_dir/authorized_keys"
        print_success "Copied root's SSH keys to $username"
    fi

    # Display SSH key info
    echo ""
    print_info "SSH Public Key for $username:"
    echo "─────────────────────────────────────────────────────────"
    cat "$ssh_dir/id_rsa.pub"
    echo "─────────────────────────────────────────────────────────"
    echo ""

    print_success "User $username setup complete"
}

# Add SSH key to user
add_ssh_key_to_user() {
    local username=$1
    local public_key=$2
    local ssh_dir="/home/$username/.ssh"

    mkdir -p "$ssh_dir"
    echo "$public_key" >> "$ssh_dir/authorized_keys"
    chmod 600 "$ssh_dir/authorized_keys"
    chown -R "$username:$username" "$ssh_dir"

    print_success "SSH key added to $username"
}

# Configure user shell
configure_user_shell() {
    local username=$1
    local bashrc="/home/$username/.bashrc"

    # Add useful aliases
    cat >> "$bashrc" <<'EOF'

# Laravel Deployer aliases
alias art='php artisan'
alias composer='composer'
alias phpunit='vendor/bin/phpunit'
alias pest='vendor/bin/pest'

# Common shortcuts
alias ll='ls -alF'
alias la='ls -A'
alias l='ls -CF'

# Git shortcuts
alias gs='git status'
alias ga='git add'
alias gc='git commit'
alias gp='git push'
alias gl='git log --oneline'

EOF

    chown "$username:$username" "$bashrc"
    print_success "Shell configured for $username"
}
