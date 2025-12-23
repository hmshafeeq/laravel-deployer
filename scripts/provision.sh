#!/bin/bash

################################################################################
# Laravel Deployer - Server Provisioning Script
#
# This script provisions a fresh Ubuntu server with all necessary components
# for Laravel application deployment.
################################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration file path (passed as argument)
CONFIG_FILE="${1:-/tmp/provision-config.sh}"

# Load configuration
if [ ! -f "$CONFIG_FILE" ]; then
    echo -e "${RED}Error: Configuration file not found: $CONFIG_FILE${NC}"
    exit 1
fi

source "$CONFIG_FILE"

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPONENTS_DIR="$SCRIPT_DIR/components"

# Load common functions
source "$COMPONENTS_DIR/common.sh"

################################################################################
# Main Provisioning Flow
################################################################################

print_header "Laravel Deployer - Server Provisioning"

echo -e "${BLUE}Starting server provisioning...${NC}"
echo ""

# Display configuration summary
print_section "Configuration Summary"
echo "PHP Version: $PHP_VERSION"
echo "Node.js Version: $NODEJS_VERSION"
echo "Create Deploy User: $([ $CREATE_USER -eq 1 ] && echo 'Yes' || echo 'No')"
[ $CREATE_USER -eq 1 ] && echo "Deploy User: $DEPLOY_USER"
echo "Install MySQL: $([ $INSTALL_MYSQL -eq 1 ] && echo 'Yes' || echo 'No')"
echo "Install PostgreSQL: $([ $INSTALL_POSTGRESQL -eq 1 ] && echo 'Yes' || echo 'No')"
echo "Install Redis: $([ $INSTALL_REDIS -eq 1 ] && echo 'Yes' || echo 'No')"
echo "Install Supervisor: $([ $INSTALL_SUPERVISOR -eq 1 ] && echo 'Yes' || echo 'No')"
echo "Setup Firewall: $([ $SETUP_FIREWALL -eq 1 ] && echo 'Yes' || echo 'No')"
echo "Setup Swap: $([ $SETUP_SWAP -eq 1 ] && echo 'Yes' || echo 'No')"
echo ""

# Update system
print_section "Updating System Packages"
source "$COMPONENTS_DIR/packages.sh"
update_system

# Setup swap if requested
if [ $SETUP_SWAP -eq 1 ]; then
    print_section "Setting Up Swap Space"
    source "$COMPONENTS_DIR/swap.sh"
    setup_swap "$SWAP_SIZE"
fi

# Install essential packages
print_section "Installing Essential Packages"
install_essential_packages

# Create deployment user if requested
if [ $CREATE_USER -eq 1 ]; then
    print_section "Creating Deployment User"
    source "$COMPONENTS_DIR/user.sh"
    create_deploy_user "$DEPLOY_USER" "$DEPLOY_PASSWORD"
fi

# Install PHP
print_section "Installing PHP $PHP_VERSION"
source "$COMPONENTS_DIR/php.sh"
install_php "$PHP_VERSION"

# Install Nginx
print_section "Installing Nginx"
source "$COMPONENTS_DIR/nginx.sh"
install_nginx

# Install Node.js
print_section "Installing Node.js $NODEJS_VERSION"
source "$COMPONENTS_DIR/nodejs.sh"
install_nodejs "$NODEJS_VERSION"

# Install Composer
print_section "Installing Composer"
source "$COMPONENTS_DIR/composer.sh"
install_composer

# Install databases
source "$COMPONENTS_DIR/database.sh"

if [ $INSTALL_MYSQL -eq 1 ]; then
    print_section "Installing MySQL"
    install_mysql "$MYSQL_ROOT_PASSWORD"
fi

if [ $INSTALL_POSTGRESQL -eq 1 ]; then
    print_section "Installing PostgreSQL"
    install_postgresql "$POSTGRES_PASSWORD"
fi

if [ $INSTALL_REDIS -eq 1 ]; then
    print_section "Installing Redis"
    install_redis
fi

# Install Supervisor
if [ $INSTALL_SUPERVISOR -eq 1 ]; then
    print_section "Installing Supervisor"
    source "$COMPONENTS_DIR/supervisor.sh"
    install_supervisor
fi

# Setup firewall
if [ $SETUP_FIREWALL -eq 1 ]; then
    print_section "Configuring Firewall"
    source "$COMPONENTS_DIR/security.sh"
    setup_firewall
fi

# Security hardening
print_section "Security Hardening"
source "$COMPONENTS_DIR/security.sh"
harden_ssh
setup_fail2ban

# Final configuration
print_section "Final Configuration"

# Set proper permissions
DEPLOY_USER_NAME="${DEPLOY_USER:-ubuntu}"
chown -R "$DEPLOY_USER_NAME:$DEPLOY_USER_NAME" /var/www 2>/dev/null || true

# Enable and start services
systemctl enable nginx
systemctl enable php${PHP_VERSION}-fpm
systemctl restart nginx
systemctl restart php${PHP_VERSION}-fpm

[ $INSTALL_REDIS -eq 1 ] && systemctl enable redis-server && systemctl restart redis-server
[ $INSTALL_MYSQL -eq 1 ] && systemctl enable mysql && systemctl restart mysql
[ $INSTALL_POSTGRESQL -eq 1 ] && systemctl enable postgresql && systemctl restart postgresql
[ $INSTALL_SUPERVISOR -eq 1 ] && systemctl enable supervisor && systemctl restart supervisor

# Display success message
print_header "Provisioning Complete!"
echo ""
echo -e "${GREEN}✅ Server has been provisioned successfully!${NC}"
echo ""
echo "Installed Software:"
echo "  • Nginx"
echo "  • PHP $PHP_VERSION"
echo "  • Node.js $NODEJS_VERSION"
echo "  • Composer"
[ $INSTALL_MYSQL -eq 1 ] && echo "  • MySQL"
[ $INSTALL_POSTGRESQL -eq 1 ] && echo "  • PostgreSQL"
[ $INSTALL_REDIS -eq 1 ] && echo "  • Redis"
[ $INSTALL_SUPERVISOR -eq 1 ] && echo "  • Supervisor"
echo ""

if [ $CREATE_USER -eq 1 ]; then
    echo -e "${YELLOW}Deployment User Information:${NC}"
    echo "  User: $DEPLOY_USER"
    echo "  SSH Key: /home/$DEPLOY_USER/.ssh/id_rsa"
    echo "  Public Key: /home/$DEPLOY_USER/.ssh/id_rsa.pub"
    echo ""
    echo "Download the private key to use it for deployments:"
    echo "  scp root@your-server:/home/$DEPLOY_USER/.ssh/id_rsa ./deploy_key"
    echo ""
fi

echo -e "${GREEN}Your server is ready for Laravel application deployment!${NC}"
echo ""
