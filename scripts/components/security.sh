#!/bin/bash

################################################################################
# Security Configuration and Hardening
################################################################################

# Setup UFW Firewall
setup_firewall() {
    print_info "Setting up UFW firewall..."

    # Install UFW if not already installed
    install_package "ufw"

    # Reset UFW to default
    ufw --force reset >/dev/null 2>&1

    # Set default policies
    ufw default deny incoming >/dev/null 2>&1
    ufw default allow outgoing >/dev/null 2>&1

    # Allow SSH (be careful not to lock yourself out!)
    ufw allow 22/tcp comment 'SSH' >/dev/null 2>&1

    # Allow HTTP and HTTPS
    ufw allow 80/tcp comment 'HTTP' >/dev/null 2>&1
    ufw allow 443/tcp comment 'HTTPS' >/dev/null 2>&1

    # Enable UFW
    ufw --force enable >/dev/null 2>&1

    print_success "Firewall configured and enabled"

    # Display status
    print_info "Firewall rules:"
    ufw status numbered
}

# Harden SSH configuration
harden_ssh() {
    print_info "Hardening SSH configuration..."

    local sshd_config="/etc/ssh/sshd_config"

    # Backup original config
    backup_file "$sshd_config"

    # Disable root login
    sed -i 's/^#*PermitRootLogin.*/PermitRootLogin prohibit-password/' "$sshd_config"

    # Disable password authentication (only after SSH keys are set up)
    # sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' "$sshd_config"

    # Disable empty passwords
    sed -i 's/^#*PermitEmptyPasswords.*/PermitEmptyPasswords no/' "$sshd_config"

    # Disable X11 forwarding
    sed -i 's/^#*X11Forwarding.*/X11Forwarding no/' "$sshd_config"

    # Set max auth tries
    sed -i 's/^#*MaxAuthTries.*/MaxAuthTries 3/' "$sshd_config"

    # Set login grace time
    sed -i 's/^#*LoginGraceTime.*/LoginGraceTime 30/' "$sshd_config"

    # Use privilege separation
    add_line_to_file "UsePrivilegeSeparation sandbox" "$sshd_config"

    # Only use strong ciphers
    add_line_to_file "Ciphers chacha20-poly1305@openssh.com,aes256-gcm@openssh.com,aes128-gcm@openssh.com,aes256-ctr,aes192-ctr,aes128-ctr" "$sshd_config"

    # Only use strong MACs
    add_line_to_file "MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com,hmac-sha2-512,hmac-sha2-256" "$sshd_config"

    # Only use strong key exchange algorithms
    add_line_to_file "KexAlgorithms curve25519-sha256,curve25519-sha256@libssh.org,diffie-hellman-group16-sha512,diffie-hellman-group18-sha512,diffie-hellman-group-exchange-sha256" "$sshd_config"

    # Restart SSH to apply changes
    systemctl restart sshd

    print_success "SSH hardened"
}

# Setup Fail2Ban
setup_fail2ban() {
    print_info "Setting up Fail2Ban..."

    # Install Fail2Ban
    install_package "fail2ban"

    # Create local configuration
    local jail_local="/etc/fail2ban/jail.local"

    cat > "$jail_local" <<'EOF'
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5
destemail = root@localhost
sendername = Fail2Ban
action = %(action_mwl)s

[sshd]
enabled = true
port = ssh
logpath = /var/log/auth.log
maxretry = 3
bantime = 7200

[nginx-http-auth]
enabled = true
port = http,https
logpath = /var/log/nginx/error.log

[nginx-noscript]
enabled = true
port = http,https
logpath = /var/log/nginx/access.log
maxretry = 6

[nginx-badbots]
enabled = true
port = http,https
logpath = /var/log/nginx/access.log
maxretry = 2

[nginx-noproxy]
enabled = true
port = http,https
logpath = /var/log/nginx/access.log
maxretry = 2
EOF

    # Start and enable Fail2Ban
    systemctl enable fail2ban
    systemctl restart fail2ban

    print_success "Fail2Ban configured and enabled"
}

# Configure automatic security updates
setup_automatic_updates() {
    print_info "Setting up automatic security updates..."

    # Install unattended-upgrades
    install_package "unattended-upgrades"
    install_package "update-notifier-common"

    # Configure automatic updates
    cat > /etc/apt/apt.conf.d/20auto-upgrades <<EOF
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
APT::Periodic::Unattended-Upgrade "1";
EOF

    # Configure unattended upgrades
    cat > /etc/apt/apt.conf.d/50unattended-upgrades <<EOF
Unattended-Upgrade::Allowed-Origins {
    "\${distro_id}:\${distro_codename}";
    "\${distro_id}:\${distro_codename}-security";
    "\${distro_id}ESMApps:\${distro_codename}-apps-security";
    "\${distro_id}ESM:\${distro_codename}-infra-security";
};

Unattended-Upgrade::AutoFixInterruptedDpkg "true";
Unattended-Upgrade::MinimalSteps "true";
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
EOF

    print_success "Automatic security updates configured"
}

# Secure shared memory
secure_shared_memory() {
    print_info "Securing shared memory..."

    # Check if already configured
    if ! grep -q "tmpfs /run/shm tmpfs" /etc/fstab; then
        echo "tmpfs /run/shm tmpfs defaults,noexec,nosuid 0 0" >> /etc/fstab
        mount -o remount /run/shm 2>/dev/null || true
        print_success "Shared memory secured"
    else
        print_info "Shared memory already secured"
    fi
}

# Configure file permissions
secure_file_permissions() {
    print_info "Configuring secure file permissions..."

    # Secure /tmp directory
    chmod 1777 /tmp
    chmod 1777 /var/tmp

    # Secure sensitive files
    chmod 600 /etc/ssh/sshd_config 2>/dev/null || true
    chmod 644 /etc/passwd
    chmod 644 /etc/group
    chmod 600 /etc/shadow
    chmod 600 /etc/gshadow

    print_success "File permissions configured"
}

# Disable unnecessary services
disable_unnecessary_services() {
    print_info "Disabling unnecessary services..."

    local services=(
        "avahi-daemon"
        "cups"
        "isc-dhcp-server"
        "isc-dhcp-server6"
        "bluetooth"
    )

    for service in "${services[@]}"; do
        if systemctl is-enabled "$service" >/dev/null 2>&1; then
            systemctl stop "$service" >/dev/null 2>&1 || true
            systemctl disable "$service" >/dev/null 2>&1 || true
            print_info "Disabled $service"
        fi
    done

    print_success "Unnecessary services disabled"
}

# Full security hardening
full_security_hardening() {
    setup_firewall
    harden_ssh
    setup_fail2ban
    setup_automatic_updates
    secure_shared_memory
    secure_file_permissions
    disable_unnecessary_services

    print_success "Security hardening complete"
}
