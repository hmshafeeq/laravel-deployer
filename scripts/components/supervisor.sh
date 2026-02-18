#!/bin/bash

################################################################################
# Supervisor Installation and Configuration
################################################################################

install_supervisor() {
    print_info "Installing Supervisor..."

    # Install Supervisor
    install_package "supervisor"

    print_success "Supervisor installed"

    # Configure Supervisor
    configure_supervisor

    # Start and enable Supervisor
    systemctl enable supervisor
    systemctl start supervisor

    print_success "Supervisor configured and started"
}

configure_supervisor() {
    print_info "Configuring Supervisor..."

    local supervisor_conf="/etc/supervisor/supervisord.conf"
    local conf_dir="/etc/supervisor/conf.d"

    # Ensure conf.d directory exists
    mkdir -p "$conf_dir"

    # Backup original config
    if [ -f "$supervisor_conf" ]; then
        backup_file "$supervisor_conf"
    fi

    # Create or update supervisor config
    cat > "$supervisor_conf" <<'EOF'
[unix_http_server]
file=/var/run/supervisor.sock
chmod=0700

[supervisord]
logfile=/var/log/supervisor/supervisord.log
pidfile=/var/run/supervisord.pid
childlogdir=/var/log/supervisor
nodaemon=false
minfds=1024
minprocs=200
user=root

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix:///var/run/supervisor.sock

[include]
files = /etc/supervisor/conf.d/*.conf
EOF

    # Create log directory
    mkdir -p /var/log/supervisor
    chmod 755 /var/log/supervisor

    print_success "Supervisor base configuration complete"
}

# Create Laravel queue worker configuration
create_laravel_worker_config() {
    local app_name=$1
    local app_path=$2
    local username=${3:-www-data}
    local num_procs=${4:-3}
    local php_version=${5:-8.3}

    local conf_file="/etc/supervisor/conf.d/${app_name}-worker.conf"

    print_info "Creating worker configuration for $app_name..."

    cat > "$conf_file" <<EOF
[program:${app_name}-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php${php_version} ${app_path}/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=${username}
numprocs=${num_procs}
redirect_stderr=true
stdout_logfile=${app_path}/storage/logs/worker.log
stopwaitsecs=3600
EOF

    # Reload Supervisor
    supervisorctl reread >/dev/null 2>&1
    supervisorctl update >/dev/null 2>&1

    print_success "Worker configuration created for $app_name"
}

# Create Laravel Horizon configuration
create_horizon_config() {
    local app_name=$1
    local app_path=$2
    local username=${3:-www-data}
    local php_version=${4:-8.3}

    local conf_file="/etc/supervisor/conf.d/${app_name}-horizon.conf"

    print_info "Creating Horizon configuration for $app_name..."

    cat > "$conf_file" <<EOF
[program:${app_name}-horizon]
process_name=%(program_name)s
command=/usr/bin/php${php_version} ${app_path}/artisan horizon
autostart=true
autorestart=true
user=${username}
redirect_stderr=true
stdout_logfile=${app_path}/storage/logs/horizon.log
stopwaitsecs=3600
EOF

    # Reload Supervisor
    supervisorctl reread >/dev/null 2>&1
    supervisorctl update >/dev/null 2>&1

    print_success "Horizon configuration created for $app_name"
}

# Create Laravel scheduler cron job
setup_laravel_scheduler() {
    local app_path=$1
    local username=${2:-www-data}
    local php_version=${3:-8.3}

    print_info "Setting up Laravel scheduler..."

    # Add cron job for Laravel scheduler
    local cron_command="* * * * * cd ${app_path} && /usr/bin/php${php_version} artisan schedule:run >> /dev/null 2>&1"

    # Add to user's crontab
    (crontab -u "$username" -l 2>/dev/null; echo "$cron_command") | crontab -u "$username" -

    print_success "Laravel scheduler configured"
}

# Create generic supervisor program
create_supervisor_program() {
    local program_name=$1
    local command=$2
    local username=${3:-www-data}
    local directory=${4:-/var/www}
    local num_procs=${5:-1}

    local conf_file="/etc/supervisor/conf.d/${program_name}.conf"

    print_info "Creating supervisor program: $program_name..."

    cat > "$conf_file" <<EOF
[program:${program_name}]
process_name=%(program_name)s_%(process_num)02d
command=${command}
autostart=true
autorestart=true
user=${username}
numprocs=${num_procs}
redirect_stderr=true
stdout_logfile=/var/log/supervisor/${program_name}.log
directory=${directory}
EOF

    # Reload Supervisor
    supervisorctl reread >/dev/null 2>&1
    supervisorctl update >/dev/null 2>&1

    print_success "Supervisor program created: $program_name"
}

# Manage supervisor programs
supervisor_control() {
    local action=$1
    local program=$2

    case $action in
        start)
            supervisorctl start "$program"
            ;;
        stop)
            supervisorctl stop "$program"
            ;;
        restart)
            supervisorctl restart "$program"
            ;;
        status)
            supervisorctl status "$program"
            ;;
        reload)
            supervisorctl reread
            supervisorctl update
            ;;
        *)
            print_error "Unknown action: $action"
            return 1
            ;;
    esac
}
