#!/bin/bash

################################################################################
# Composer Installation
################################################################################

install_composer() {
    print_info "Installing Composer..."

    # Download and install Composer
    local expected_checksum="$(wget -q -O - https://composer.github.io/installer.sig)"
    local installer="/tmp/composer-installer.php"

    # Download installer
    wget -q -O "$installer" https://getcomposer.org/installer

    # Verify installer
    local actual_checksum="$(php -r "echo hash_file('sha384', '$installer');")"

    if [ "$expected_checksum" != "$actual_checksum" ]; then
        print_error "Composer installer checksum mismatch"
        rm -f "$installer"
        return 1
    fi

    # Install Composer globally
    php "$installer" --quiet --install-dir=/usr/local/bin --filename=composer

    # Cleanup
    rm -f "$installer"

    # Verify installation
    local composer_version=$(composer --version 2>/dev/null | grep -oP 'Composer version \K[0-9.]+' || echo "")

    if [ -n "$composer_version" ]; then
        print_success "Composer $composer_version installed"
    else
        print_error "Composer installation failed"
        return 1
    fi

    # Configure Composer
    configure_composer
}

configure_composer() {
    print_info "Configuring Composer..."

    # Set global configuration
    composer config -g process-timeout 2000
    composer config -g optimize-autoloader true

    # Disable memory limit for Composer
    echo 'export COMPOSER_MEMORY_LIMIT=-1' >> /etc/profile.d/composer.sh
    chmod +x /etc/profile.d/composer.sh

    print_success "Composer configured"
}

# Configure Composer for deploy user
configure_composer_for_user() {
    local username=$1

    print_info "Configuring Composer for $username..."

    local composer_dir="/home/$username/.composer"
    local cache_dir="$composer_dir/cache"

    # Create Composer directories
    mkdir -p "$cache_dir"
    chown -R "$username:$username" "$composer_dir"

    # Configure for user
    sudo -u "$username" composer config -g process-timeout 2000
    sudo -u "$username" composer config -g optimize-autoloader true

    print_success "Composer configured for $username"
}

# Install Composer package globally
install_composer_global_package() {
    local package=$1

    print_info "Installing global package: $package..."

    composer global require "$package" >/dev/null 2>&1

    print_success "$package installed globally"
}
