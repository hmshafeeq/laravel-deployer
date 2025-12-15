#!/bin/bash

################################################################################
# PHP Installation and Configuration
################################################################################

install_php() {
    local php_version=$1

    print_info "Installing PHP $php_version..."

    # Add Ondrej PHP repository
    print_info "Adding PHP repository..."
    add_repository "ppa:ondrej/php"

    # Install PHP and common extensions
    local php_packages=(
        "php${php_version}"
        "php${php_version}-fpm"
        "php${php_version}-cli"
        "php${php_version}-common"
        "php${php_version}-mysql"
        "php${php_version}-pgsql"
        "php${php_version}-sqlite3"
        "php${php_version}-redis"
        "php${php_version}-curl"
        "php${php_version}-gd"
        "php${php_version}-mbstring"
        "php${php_version}-xml"
        "php${php_version}-xmlrpc"
        "php${php_version}-zip"
        "php${php_version}-bcmath"
        "php${php_version}-soap"
        "php${php_version}-intl"
        "php${php_version}-readline"
        "php${php_version}-opcache"
        "php${php_version}-imagick"
    )

    for package in "${php_packages[@]}"; do
        install_package "$package"
    done

    print_success "PHP $php_version installed"

    # Configure PHP
    configure_php "$php_version"

    # Configure PHP-FPM
    configure_php_fpm "$php_version"
}

configure_php() {
    local php_version=$1
    local php_ini_cli="/etc/php/${php_version}/cli/php.ini"
    local php_ini_fpm="/etc/php/${php_version}/fpm/php.ini"

    print_info "Configuring PHP..."

    # Backup original files
    backup_file "$php_ini_cli"
    backup_file "$php_ini_fpm"

    # Configure CLI php.ini
    sed -i "s/memory_limit = .*/memory_limit = 512M/" "$php_ini_cli"
    sed -i "s/upload_max_filesize = .*/upload_max_filesize = 100M/" "$php_ini_cli"
    sed -i "s/post_max_size = .*/post_max_size = 100M/" "$php_ini_cli"
    sed -i "s/max_execution_time = .*/max_execution_time = 300/" "$php_ini_cli"
    sed -i "s/;date.timezone.*/date.timezone = UTC/" "$php_ini_cli"

    # Configure FPM php.ini
    sed -i "s/memory_limit = .*/memory_limit = 512M/" "$php_ini_fpm"
    sed -i "s/upload_max_filesize = .*/upload_max_filesize = 100M/" "$php_ini_fpm"
    sed -i "s/post_max_size = .*/post_max_size = 100M/" "$php_ini_fpm"
    sed -i "s/max_execution_time = .*/max_execution_time = 300/" "$php_ini_fpm"
    sed -i "s/;date.timezone.*/date.timezone = UTC/" "$php_ini_fpm"

    # Configure OPcache
    sed -i "s/;opcache.enable=.*/opcache.enable=1/" "$php_ini_fpm"
    sed -i "s/;opcache.memory_consumption=.*/opcache.memory_consumption=256/" "$php_ini_fpm"
    sed -i "s/;opcache.interned_strings_buffer=.*/opcache.interned_strings_buffer=16/" "$php_ini_fpm"
    sed -i "s/;opcache.max_accelerated_files=.*/opcache.max_accelerated_files=10000/" "$php_ini_fpm"
    sed -i "s/;opcache.validate_timestamps=.*/opcache.validate_timestamps=0/" "$php_ini_fpm"

    print_success "PHP configuration updated"
}

configure_php_fpm() {
    local php_version=$1
    local fpm_pool="/etc/php/${php_version}/fpm/pool.d/www.conf"

    print_info "Configuring PHP-FPM..."

    backup_file "$fpm_pool"

    # Update FPM pool configuration
    sed -i "s/user = www-data/user = ${DEPLOY_USER:-www-data}/" "$fpm_pool"
    sed -i "s/group = www-data/group = ${DEPLOY_USER:-www-data}/" "$fpm_pool"
    sed -i "s/;listen.owner = www-data/listen.owner = ${DEPLOY_USER:-www-data}/" "$fpm_pool"
    sed -i "s/;listen.group = www-data/listen.group = ${DEPLOY_USER:-www-data}/" "$fpm_pool"
    sed -i "s/;listen.mode = 0660/listen.mode = 0660/" "$fpm_pool"

    # Performance tuning
    sed -i "s/pm.max_children = .*/pm.max_children = 50/" "$fpm_pool"
    sed -i "s/pm.start_servers = .*/pm.start_servers = 5/" "$fpm_pool"
    sed -i "s/pm.min_spare_servers = .*/pm.min_spare_servers = 5/" "$fpm_pool"
    sed -i "s/pm.max_spare_servers = .*/pm.max_spare_servers = 35/" "$fpm_pool"

    print_success "PHP-FPM configured"

    # Restart PHP-FPM
    systemctl restart "php${php_version}-fpm"
    print_success "PHP-FPM restarted"
}

# Install additional PHP extensions
install_php_extension() {
    local php_version=$1
    local extension=$2

    install_package "php${php_version}-${extension}"
    systemctl restart "php${php_version}-fpm"
}
