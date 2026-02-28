#!/bin/bash
set -euo pipefail

DEPLOY_PATH="/var/www/staging"
CURRENT_PATH="$DEPLOY_PATH/current"

if [ ! -L "$CURRENT_PATH" ]; then
    echo "current symlink not found. Run setup init first." >&2
    exit 1
fi

echo "Applying drift mutations on existing server..."

if [ -f /etc/nginx/sites-available/staging ]; then
    sed -i 's|root /var/www/staging/current/public|root /var/www/staging/public|g' /etc/nginx/sites-available/staging
    nginx -t >/dev/null 2>&1 || true
    systemctl reload nginx >/dev/null 2>&1 || true
fi

chown -R root:root "$CURRENT_PATH/storage" "$CURRENT_PATH/bootstrap/cache"
find "$CURRENT_PATH/storage" "$CURRENT_PATH/bootstrap/cache" -type d -exec chmod 755 {} \;
find "$CURRENT_PATH/storage" "$CURRENT_PATH/bootstrap/cache" -type f -exec chmod 644 {} \;
find "$CURRENT_PATH" -type d -exec chmod g-s {} \;

echo "Drift mutations applied."
