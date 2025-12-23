#!/bin/bash

################################################################################
# Node.js Installation
################################################################################

install_nodejs() {
    local nodejs_version=$1

    print_info "Installing Node.js $nodejs_version..."

    # Remove any existing Node.js installations
    apt-get remove -y nodejs npm >/dev/null 2>&1 || true

    # Install Node.js from NodeSource
    print_info "Adding NodeSource repository..."

    curl -fsSL "https://deb.nodesource.com/setup_${nodejs_version}.x" | bash - >/dev/null 2>&1

    # Install Node.js and npm
    install_package "nodejs"

    # Verify installation
    local installed_version=$(node --version 2>/dev/null || echo "")

    if [ -n "$installed_version" ]; then
        print_success "Node.js $installed_version installed"
    else
        print_error "Node.js installation failed"
        return 1
    fi

    # Install Yarn
    install_yarn

    # Install common global packages
    install_global_npm_packages
}

install_yarn() {
    print_info "Installing Yarn..."

    # Install Yarn via npm
    npm install -g yarn >/dev/null 2>&1

    local yarn_version=$(yarn --version 2>/dev/null || echo "")

    if [ -n "$yarn_version" ]; then
        print_success "Yarn $yarn_version installed"
    else
        print_warning "Yarn installation failed"
    fi
}

install_global_npm_packages() {
    print_info "Installing global npm packages..."

    local packages=(
        "pm2"           # Process manager
        "npm-check-updates"  # Package updater
    )

    for package in "${packages[@]}"; do
        npm install -g "$package" >/dev/null 2>&1 && print_success "$package installed" || print_warning "$package installation failed"
    done
}

# Configure npm for deploy user
configure_npm_for_user() {
    local username=$1

    print_info "Configuring npm for $username..."

    # Create npm directories with proper permissions
    local npm_dir="/home/$username/.npm"
    local npm_global="/home/$username/.npm-global"

    mkdir -p "$npm_dir" "$npm_global"
    chown -R "$username:$username" "$npm_dir" "$npm_global"

    # Configure npm to use global directory
    sudo -u "$username" npm config set prefix "$npm_global"

    # Add to PATH in .bashrc
    local bashrc="/home/$username/.bashrc"
    add_line_to_file "export PATH=\$HOME/.npm-global/bin:\$PATH" "$bashrc"

    print_success "npm configured for $username"
}
