#!/bin/bash

################################################################################
# System Package Management Functions
################################################################################

# Update system packages
update_system() {
    print_info "Updating package lists..."
    wait_for_apt

    export DEBIAN_FRONTEND=noninteractive

    # Update package lists
    apt-get update -qq >/dev/null 2>&1
    print_success "Package lists updated"

    # Upgrade packages
    print_info "Upgrading installed packages..."
    apt-get upgrade -y -qq >/dev/null 2>&1
    print_success "Packages upgraded"

    # Install security updates
    print_info "Installing security updates..."
    apt-get dist-upgrade -y -qq >/dev/null 2>&1
    print_success "Security updates installed"
}

# Install essential packages
install_essential_packages() {
    print_info "Installing essential packages..."

    local packages=(
        # Build tools
        "build-essential"
        "gcc"
        "g++"
        "make"

        # Version control
        "git"
        "git-core"

        # Network tools
        "curl"
        "wget"
        "gnupg"
        "ca-certificates"
        "lsb-release"

        # Compression tools
        "zip"
        "unzip"
        "tar"
        "gzip"

        # System utilities
        "software-properties-common"
        "apt-transport-https"
        "ufw"
        "fail2ban"
        "htop"
        "vim"
        "nano"

        # SSL/TLS
        "openssl"
        "ssl-cert"

        # Process management
        "supervisor"

        # Python (for scripts)
        "python3"
        "python3-pip"
    )

    for package in "${packages[@]}"; do
        install_package "$package"
    done

    print_success "Essential packages installed"
}

# Clean up package manager
cleanup_packages() {
    print_info "Cleaning up package manager..."

    apt-get autoremove -y -qq >/dev/null 2>&1
    apt-get autoclean -y -qq >/dev/null 2>&1
    apt-get clean -y -qq >/dev/null 2>&1

    print_success "Package cleanup complete"
}

# Add repository
add_repository() {
    local repo=$1

    if ! grep -q "^deb .*$repo" /etc/apt/sources.list /etc/apt/sources.list.d/*; then
        add-apt-repository -y "$repo" >/dev/null 2>&1
        apt-get update -qq >/dev/null 2>&1
        print_success "Repository added: $repo"
    else
        print_info "Repository already exists: $repo"
    fi
}

# Add GPG key
add_gpg_key() {
    local url=$1
    local keyring=$2

    if [ ! -f "$keyring" ]; then
        curl -fsSL "$url" | gpg --dearmor -o "$keyring"
        print_success "GPG key added: $keyring"
    else
        print_info "GPG key already exists: $keyring"
    fi
}
