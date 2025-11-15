#!/bin/bash

################################################################################
# Database Installation Functions
################################################################################

# Install MySQL
install_mysql() {
    local root_password=$1

    print_info "Installing MySQL..."

    # Set root password before installation
    if [ -n "$root_password" ]; then
        debconf-set-selections <<< "mysql-server mysql-server/root_password password $root_password"
        debconf-set-selections <<< "mysql-server mysql-server/root_password_again password $root_password"
    fi

    # Install MySQL
    install_package "mysql-server"
    install_package "mysql-client"

    print_success "MySQL installed"

    # Configure MySQL
    configure_mysql "$root_password"
}

configure_mysql() {
    local root_password=$1

    print_info "Configuring MySQL..."

    # Start MySQL
    systemctl start mysql
    systemctl enable mysql

    # Secure installation
    if [ -n "$root_password" ]; then
        mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$root_password';" 2>/dev/null || true
        mysql -uroot -p"$root_password" -e "DELETE FROM mysql.user WHERE User='';" 2>/dev/null || true
        mysql -uroot -p"$root_password" -e "DROP DATABASE IF EXISTS test;" 2>/dev/null || true
        mysql -uroot -p"$root_password" -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';" 2>/dev/null || true
        mysql -uroot -p"$root_password" -e "FLUSH PRIVILEGES;" 2>/dev/null || true
    fi

    # Configure for performance
    local mysql_conf="/etc/mysql/mysql.conf.d/mysqld.cnf"

    if [ -f "$mysql_conf" ]; then
        backup_file "$mysql_conf"

        # Add performance settings
        cat >> "$mysql_conf" <<EOF

# Performance tuning
max_connections = 200
query_cache_size = 16M
query_cache_limit = 1M
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT
EOF

        systemctl restart mysql
    fi

    print_success "MySQL configured"
}

# Install PostgreSQL
install_postgresql() {
    local postgres_password=$1

    print_info "Installing PostgreSQL..."

    # Install PostgreSQL
    install_package "postgresql"
    install_package "postgresql-contrib"

    print_success "PostgreSQL installed"

    # Configure PostgreSQL
    configure_postgresql "$postgres_password"
}

configure_postgresql() {
    local postgres_password=$1

    print_info "Configuring PostgreSQL..."

    # Start PostgreSQL
    systemctl start postgresql
    systemctl enable postgresql

    # Set postgres user password
    if [ -n "$postgres_password" ]; then
        sudo -u postgres psql -c "ALTER USER postgres PASSWORD '$postgres_password';" 2>/dev/null || true
    fi

    # Configure authentication
    local pg_hba="/etc/postgresql/*/main/pg_hba.conf"

    if ls $pg_hba 1> /dev/null 2>&1; then
        for conf in $pg_hba; do
            backup_file "$conf"

            # Allow password authentication
            sed -i 's/local\s*all\s*postgres\s*peer/local   all             postgres                                md5/' "$conf"
            sed -i 's/local\s*all\s*all\s*peer/local   all             all                                     md5/' "$conf"
        done

        systemctl restart postgresql
    fi

    print_success "PostgreSQL configured"
}

# Install Redis
install_redis() {
    print_info "Installing Redis..."

    # Install Redis
    install_package "redis-server"

    print_success "Redis installed"

    # Configure Redis
    configure_redis
}

configure_redis() {
    print_info "Configuring Redis..."

    local redis_conf="/etc/redis/redis.conf"

    if [ -f "$redis_conf" ]; then
        backup_file "$redis_conf"

        # Configure Redis
        sed -i 's/supervised no/supervised systemd/' "$redis_conf"
        sed -i 's/# maxmemory <bytes>/maxmemory 256mb/' "$redis_conf"
        sed -i 's/# maxmemory-policy noeviction/maxmemory-policy allkeys-lru/' "$redis_conf"

        # Start and enable Redis
        systemctl restart redis-server
        systemctl enable redis-server
    fi

    print_success "Redis configured"
}

# Create database and user
create_database() {
    local db_type=$1
    local db_name=$2
    local db_user=$3
    local db_password=$4
    local root_password=$5

    case $db_type in
        mysql)
            mysql -uroot -p"$root_password" <<EOF
CREATE DATABASE IF NOT EXISTS \`$db_name\`;
CREATE USER IF NOT EXISTS '$db_user'@'localhost' IDENTIFIED BY '$db_password';
GRANT ALL PRIVILEGES ON \`$db_name\`.* TO '$db_user'@'localhost';
FLUSH PRIVILEGES;
EOF
            print_success "MySQL database created: $db_name"
            ;;

        postgresql)
            sudo -u postgres psql <<EOF
CREATE DATABASE $db_name;
CREATE USER $db_user WITH ENCRYPTED PASSWORD '$db_password';
GRANT ALL PRIVILEGES ON DATABASE $db_name TO $db_user;
EOF
            print_success "PostgreSQL database created: $db_name"
            ;;

        *)
            print_error "Unknown database type: $db_type"
            return 1
            ;;
    esac
}
