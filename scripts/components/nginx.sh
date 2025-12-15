#!/bin/bash

################################################################################
# Nginx Installation and Configuration
################################################################################

install_nginx() {
    print_info "Installing Nginx..."

    # Install Nginx
    install_package "nginx"

    print_success "Nginx installed"

    # Configure Nginx
    configure_nginx

    # Create web root directories
    setup_web_directories
}

configure_nginx() {
    print_info "Configuring Nginx..."

    local nginx_conf="/etc/nginx/nginx.conf"
    local sites_available="/etc/nginx/sites-available"
    local sites_enabled="/etc/nginx/sites-enabled"

    # Backup original configuration
    backup_file "$nginx_conf"

    # Create optimized nginx.conf
    cat > "$nginx_conf" <<'EOF'
user www-data;
worker_processes auto;
pid /run/nginx.pid;
include /etc/nginx/modules-enabled/*.conf;

events {
    worker_connections 2048;
    multi_accept on;
    use epoll;
}

http {
    ##
    # Basic Settings
    ##
    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    keepalive_timeout 65;
    types_hash_max_size 2048;
    server_tokens off;
    client_max_body_size 100M;

    # server_names_hash_bucket_size 64;
    # server_name_in_redirect off;

    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    ##
    # SSL Settings
    ##
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    ##
    # Logging Settings
    ##
    access_log /var/log/nginx/access.log;
    error_log /var/log/nginx/error.log;

    ##
    # Gzip Settings
    ##
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript application/json application/javascript application/xml+rss application/rss+xml font/truetype font/opentype application/vnd.ms-fontobject image/svg+xml;
    gzip_disable "msie6";

    ##
    # Virtual Host Configs
    ##
    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
EOF

    # Remove default site if exists
    if [ -f "$sites_enabled/default" ]; then
        rm -f "$sites_enabled/default"
        print_info "Removed default Nginx site"
    fi

    # Create default Laravel site template
    create_default_site_config

    # Update user if deploy user was created
    if [ -n "${DEPLOY_USER}" ] && [ "${DEPLOY_USER}" != "www-data" ]; then
        sed -i "s/user www-data;/user ${DEPLOY_USER};/" "$nginx_conf"
        print_success "Nginx user updated to ${DEPLOY_USER}"
    fi

    print_success "Nginx configured"

    # Test configuration
    if nginx -t >/dev/null 2>&1; then
        print_success "Nginx configuration is valid"
    else
        print_error "Nginx configuration has errors"
        nginx -t
        return 1
    fi

    # Restart Nginx
    systemctl restart nginx
    print_success "Nginx restarted"
}

create_default_site_config() {
    local sites_available="/etc/nginx/sites-available"
    local config_file="$sites_available/laravel-default"

    mkdir -p "$sites_available"

    cat > "$config_file" <<'EOF'
server {
    listen 80 default_server;
    listen [::]:80 default_server;

    root /var/www/html;
    index index.php index.html index.htm;

    server_name _;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Laravel specific
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP handling
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to hidden files
    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Static file handling
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
EOF

    print_success "Default site configuration created"
}

setup_web_directories() {
    print_info "Setting up web directories..."

    local web_user="${DEPLOY_USER:-www-data}"
    local web_root="/var/www"

    # Create web root
    mkdir -p "$web_root/html"

    # Create sample index page
    cat > "$web_root/html/index.php" <<'EOF'
<!DOCTYPE html>
<html>
<head>
    <title>Server Ready</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .container {
            text-align: center;
        }
        h1 {
            font-size: 3em;
            margin-bottom: 0.5em;
        }
        .info {
            background: rgba(255,255,255,0.1);
            padding: 2em;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }
        .check {
            font-size: 4em;
            margin-bottom: 0.2em;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="info">
            <div class="check">✅</div>
            <h1>Server Ready!</h1>
            <p>Nginx and PHP <?php echo PHP_VERSION; ?> are working correctly.</p>
            <p><small>Provisioned by Laravel Deployer</small></p>
        </div>
    </div>
</body>
</html>
EOF

    # Set permissions
    chown -R "$web_user:$web_user" "$web_root"
    chmod -R 755 "$web_root"

    print_success "Web directories configured"
}

# Create Nginx site configuration for Laravel
create_laravel_site() {
    local domain=$1
    local root_path=$2
    local php_version=${3:-8.3}

    local sites_available="/etc/nginx/sites-available"
    local sites_enabled="/etc/nginx/sites-enabled"
    local config_file="$sites_available/$domain"

    cat > "$config_file" <<EOF
server {
    listen 80;
    listen [::]:80;

    server_name $domain;
    root $root_path/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php${php_version}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

    # Enable site
    ln -sf "$config_file" "$sites_enabled/$domain"

    # Test and reload
    nginx -t && systemctl reload nginx

    print_success "Laravel site created: $domain"
}
