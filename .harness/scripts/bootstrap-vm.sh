#!/bin/bash
set -euo pipefail

DEFAULT_USER="${1:?default user required}"
PUBLIC_KEY_PATH="${2:?public key path required}"
DEPLOY_USER="${3:-deploy}"

if [ ! -f "$PUBLIC_KEY_PATH" ]; then
    echo "Public key not found at $PUBLIC_KEY_PATH" >&2
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

apt-get update -o Acquire::Retries=5
apt-get install -y --no-install-recommends \
    openssh-server \
    sudo \
    curl \
    git \
    rsync \
    unzip \
    ca-certificates \
    gnupg

mkdir -p /run/sshd

if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
    useradd -m -s /bin/bash "$DEPLOY_USER"
fi

if ! getent group www-data >/dev/null 2>&1; then
    groupadd -r www-data
fi

usermod -a -G sudo "$DEPLOY_USER" || true
usermod -a -G www-data "$DEPLOY_USER" || true

echo "$DEPLOY_USER ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/$DEPLOY_USER"
chmod 0440 "/etc/sudoers.d/$DEPLOY_USER"

install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$PUBLIC_KEY_PATH" "/home/$DEPLOY_USER/.ssh/authorized_keys"

if [ -f /etc/ssh/sshd_config ]; then
    sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
    sed -i 's/^#\?PubkeyAuthentication.*/PubkeyAuthentication yes/' /etc/ssh/sshd_config
    sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
fi

mkdir -p /var/www
chown "$DEPLOY_USER:$DEPLOY_USER" /var/www

systemctl enable ssh >/dev/null 2>&1 || true
systemctl restart ssh >/dev/null 2>&1 || service ssh restart >/dev/null 2>&1 || true

# Keep default OrbStack user sudo-capable for root-level setup tasks via orbctl.
if id -u "$DEFAULT_USER" >/dev/null 2>&1; then
    usermod -a -G sudo "$DEFAULT_USER" || true
fi
