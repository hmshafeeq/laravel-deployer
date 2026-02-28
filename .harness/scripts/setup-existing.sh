#!/bin/bash
set -euo pipefail

APP_SOURCE="${1:-/tmp/test-app}"
APP_DIR="/var/www/staging"
PHP_VERSION="${PHP_VERSION:-8.4}"

if [ ! -d "$APP_SOURCE" ]; then
    echo "App source not found: $APP_SOURCE" >&2
    exit 1
fi

echo "==> Preparing existing flat Laravel server state..."

export DEBIAN_FRONTEND=noninteractive

apt-get update -o Acquire::Retries=5
apt-get install -y --no-install-recommends \
    software-properties-common \
    ca-certificates \
    curl \
    git \
    rsync \
    unzip \
    gnupg

if ! dpkg -s "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -o Acquire::Retries=5
fi

apt-get install -y --no-install-recommends \
    "php${PHP_VERSION}-fpm" \
    "php${PHP_VERSION}-cli" \
    "php${PHP_VERSION}-common" \
    "php${PHP_VERSION}-mysql" \
    "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-zip" \
    "php${PHP_VERSION}-bcmath" \
    "php${PHP_VERSION}-tokenizer" \
    "php${PHP_VERSION}-intl" \
    "php${PHP_VERSION}-sqlite3" \
    nginx

# Apache can be pulled in via PHP package dependencies; disable it so nginx owns :80.
systemctl stop apache2 >/dev/null 2>&1 || true
systemctl disable apache2 >/dev/null 2>&1 || true

if ! command -v composer >/dev/null 2>&1; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

mkdir -p /run/php

PHP_FPM_POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
if [ -f "$PHP_FPM_POOL" ]; then
    sed -i 's/^user = .*/user = deploy/' "$PHP_FPM_POOL"
    sed -i 's/^group = .*/group = www-data/' "$PHP_FPM_POOL"
    sed -i "s|^listen = .*|listen = /run/php/php${PHP_VERSION}-fpm.sock|" "$PHP_FPM_POOL"
fi

rm -rf "$APP_DIR"
mkdir -p "$APP_DIR"
rsync -a --delete "$APP_SOURCE/" "$APP_DIR/"

mkdir -p "$APP_DIR/shared/database"
touch "$APP_DIR/shared/database/database.sqlite"
chown -R deploy:www-data "$APP_DIR"

cat > "$APP_DIR/.env" <<ENV
APP_NAME="Deployer Test"
APP_ENV=staging
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=sqlite
DB_DATABASE=$APP_DIR/shared/database/database.sqlite

LOG_CHANNEL=single
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
ENV

cd "$APP_DIR"
if [ -f "$APP_DIR/vendor/autoload.php" ]; then
    echo "==> Vendor directory already present, skipping composer install."
else
    composer install --no-dev --no-interaction --prefer-dist
fi

php artisan migrate --force

cat > /etc/nginx/sites-available/staging <<NGINX
server {
    listen 80 default_server;
    server_name _;
    root $APP_DIR/public;

    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
    }

    location ~ /\\.ht {
        deny all;
    }
}
NGINX

rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/staging /etc/nginx/sites-enabled/staging

systemctl enable --now "php${PHP_VERSION}-fpm"
systemctl enable --now nginx
systemctl restart "php${PHP_VERSION}-fpm"
systemctl restart nginx

chown -R deploy:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 2775 {} \;
find "$APP_DIR" -type f -exec chmod 664 {} \;
chmod -R ug+rwx "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

echo "==> Existing app setup complete."
