#!/bin/bash

################################################################################
# Common utility functions for provisioning scripts
################################################################################

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

################################################################################
# Print Functions
################################################################################

print_header() {
    echo ""
    echo -e "${MAGENTA}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${MAGENTA}  $1${NC}"
    echo -e "${MAGENTA}═══════════════════════════════════════════════════════════${NC}"
    echo ""
}

print_section() {
    echo ""
    echo -e "${CYAN}▶ $1${NC}"
    echo -e "${CYAN}───────────────────────────────────────────────────────────${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

################################################################################
# Utility Functions
################################################################################

# Check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Check if package is installed
package_installed() {
    dpkg -l "$1" 2>/dev/null | grep -q "^ii"
}

# Install package if not already installed
install_package() {
    local package=$1
    if package_installed "$package"; then
        print_info "$package is already installed"
    else
        print_info "Installing $package..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y "$package" >/dev/null 2>&1
        print_success "$package installed"
    fi
}

# Check if running as root
check_root() {
    if [ "$EUID" -ne 0 ]; then
        print_error "This script must be run as root"
        exit 1
    fi
}

# Detect Ubuntu version
detect_ubuntu_version() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        echo "$VERSION_ID"
    else
        echo "unknown"
    fi
}

# Generate random password
generate_password() {
    local length=${1:-32}
    openssl rand -base64 $length | tr -d "=+/" | cut -c1-$length
}

# Wait for apt lock
wait_for_apt() {
    while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 ; do
        print_info "Waiting for apt lock to be released..."
        sleep 1
    done
}

# Safely restart service
safe_restart_service() {
    local service=$1
    if systemctl is-active --quiet "$service"; then
        systemctl restart "$service"
        print_success "$service restarted"
    else
        systemctl start "$service"
        print_success "$service started"
    fi
}

# Add line to file if not exists
add_line_to_file() {
    local line=$1
    local file=$2
    grep -qF "$line" "$file" || echo "$line" >> "$file"
}

# Backup file
backup_file() {
    local file=$1
    if [ -f "$file" ]; then
        cp "$file" "${file}.backup.$(date +%Y%m%d%H%M%S)"
        print_info "Backed up $file"
    fi
}

# Check Ubuntu version compatibility
check_ubuntu_compatibility() {
    local version=$(detect_ubuntu_version)
    local major_version=$(echo "$version" | cut -d. -f1)

    if [ "$major_version" -lt 20 ]; then
        print_error "This script requires Ubuntu 20.04 or higher"
        print_error "Current version: Ubuntu $version"
        exit 1
    fi

    print_success "Ubuntu $version detected - compatible"
}

# Export functions for use in other scripts
export -f command_exists
export -f package_installed
export -f install_package
export -f check_root
export -f detect_ubuntu_version
export -f generate_password
export -f wait_for_apt
export -f safe_restart_service
export -f add_line_to_file
export -f backup_file
export -f check_ubuntu_compatibility
export -f print_header
export -f print_section
export -f print_success
export -f print_error
export -f print_warning
export -f print_info
