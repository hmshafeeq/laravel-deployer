#!/bin/bash

# =============================================================================
# Laravel Deployer Migration Script
# =============================================================================
# Migrates an existing Laravel deployment to laravel-deployer directory structure
# Runs from LOCAL machine, connects to server via SSH
#
# Features:
#   - Full project backup before migration
#   - Database backup (MySQL/MariaDB)
#   - Only proceeds after both backups succeed
#   - Atomic directory restructuring
#
# Usage:
#   ./migrate-to-deployer.sh <host> <domain> [options]
#
# Examples:
#   ./migrate-to-deployer.sh 192.168.1.100 thepayrollapp.com
#   ./migrate-to-deployer.sh user@server.com dev.example.com --base-path=/var/www
# =============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# =============================================================================
# Default Configuration
# =============================================================================
SSH_USER="ubuntu"
SSH_PORT="22"
SSH_KEY=""
BASE_PATH="/var/www"
WEB_USER="www-data"
DEPLOY_USER="ubuntu"
BACKUP_PATH="/var/www/backups"
DB_NAME=""
DB_USER=""
DB_PASS=""
SKIP_DB_BACKUP=false
DRY_RUN=false

# =============================================================================
# Parse Arguments
# =============================================================================
print_usage() {
    echo "Usage: $0 <host> <domain> [options]"
    echo ""
    echo "Arguments:"
    echo "  host              Server hostname or IP (can include user@ prefix)"
    echo "  domain            Domain name (e.g., thepayrollapp.com)"
    echo ""
    echo "Options:"
    echo "  --user=USER       SSH user (default: ubuntu)"
    echo "  --port=PORT       SSH port (default: 22)"
    echo "  --key=PATH        Path to SSH private key"
    echo "  --base-path=PATH  Base path for sites (default: /var/www)"
    echo "  --web-user=USER   Web server user (default: www-data)"
    echo "  --deploy-user=USER Deploy user (default: ubuntu)"
    echo "  --backup-path=PATH Backup directory (default: /var/www/backups)"
    echo "  --db-name=NAME    Database name (auto-detected from .env if not set)"
    echo "  --db-user=USER    Database user (auto-detected from .env if not set)"
    echo "  --db-pass=PASS    Database password (auto-detected from .env if not set)"
    echo "  --skip-db-backup  Skip database backup"
    echo "  --dry-run         Show what would be done without executing"
    echo "  --help            Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 192.168.1.100 thepayrollapp.com"
    echo "  $0 ubuntu@server.com example.com --key=~/.ssh/deploy_key"
    echo "  $0 server.com dev.example.com --db-name=mydb --db-user=root"
}

# Parse host argument (may contain user@)
parse_host() {
    if [[ "$1" == *"@"* ]]; then
        SSH_USER="${1%@*}"
        HOST="${1#*@}"
    else
        HOST="$1"
    fi
}

# Parse command line arguments
if [[ $# -lt 2 ]]; then
    print_usage
    exit 1
fi

parse_host "$1"
DOMAIN="$2"
shift 2

while [[ $# -gt 0 ]]; do
    case $1 in
        --user=*) SSH_USER="${1#*=}" ;;
        --port=*) SSH_PORT="${1#*=}" ;;
        --key=*) SSH_KEY="${1#*=}" ;;
        --base-path=*) BASE_PATH="${1#*=}" ;;
        --web-user=*) WEB_USER="${1#*=}" ;;
        --deploy-user=*) DEPLOY_USER="${1#*=}" ;;
        --backup-path=*) BACKUP_PATH="${1#*=}" ;;
        --db-name=*) DB_NAME="${1#*=}" ;;
        --db-user=*) DB_USER="${1#*=}" ;;
        --db-pass=*) DB_PASS="${1#*=}" ;;
        --skip-db-backup) SKIP_DB_BACKUP=true ;;
        --dry-run) DRY_RUN=true ;;
        --help) print_usage; exit 0 ;;
        *) echo "Unknown option: $1"; print_usage; exit 1 ;;
    esac
    shift
done

# Derived paths
SITE_PATH="${BASE_PATH}/${DOMAIN}"
RELEASE_NAME="$(date +%Y%m).1"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

# Build SSH command
SSH_OPTS="-o StrictHostKeyChecking=accept-new -o ConnectTimeout=10"
[[ -n "$SSH_KEY" ]] && SSH_OPTS="$SSH_OPTS -i $SSH_KEY"
SSH_OPTS="$SSH_OPTS -p $SSH_PORT"
SSH_CMD="ssh $SSH_OPTS ${SSH_USER}@${HOST}"

# =============================================================================
# Helper Functions
# =============================================================================
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
    exit 1
}

log_step() {
    echo -e "\n${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${CYAN}  $1${NC}"
    echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
}

confirm() {
    if [[ "$DRY_RUN" == "true" ]]; then
        echo -e "${YELLOW}[DRY-RUN]${NC} Would ask: $1"
        return 0
    fi
    read -p "$(echo -e ${YELLOW}[CONFIRM]${NC} $1 [y/N]: )" response
    case "$response" in
        [yY][eE][sS]|[yY]) return 0 ;;
        *) return 1 ;;
    esac
}

# Execute command on remote server
remote_exec() {
    if [[ "$DRY_RUN" == "true" ]]; then
        echo -e "${YELLOW}[DRY-RUN]${NC} Would execute: $1"
        return 0
    fi
    $SSH_CMD "bash -c '$1'"
}

# Execute command on remote server with sudo
remote_sudo() {
    if [[ "$DRY_RUN" == "true" ]]; then
        echo -e "${YELLOW}[DRY-RUN]${NC} Would execute (sudo): $1"
        return 0
    fi
    $SSH_CMD "sudo bash -c '$1'"
}

# Get value from remote .env file
get_env_value() {
    $SSH_CMD "grep -E '^$1=' $SITE_PATH/.env 2>/dev/null | cut -d'=' -f2- | tr -d '\"' | tr -d \"'\"" 2>/dev/null || echo ""
}

# =============================================================================
# Pre-flight Checks
# =============================================================================
preflight_checks() {
    log_step "Step 1/5: Pre-flight Checks"

    log_info "Testing SSH connection to ${SSH_USER}@${HOST}..."
    if ! $SSH_CMD "echo 'SSH connection successful'" 2>/dev/null; then
        log_error "Cannot connect to server via SSH. Check your credentials."
    fi
    log_success "SSH connection established"

    log_info "Checking if site path exists: $SITE_PATH"
    if ! $SSH_CMD "test -d '$SITE_PATH'" 2>/dev/null; then
        log_error "Site path does not exist: $SITE_PATH"
    fi
    log_success "Site path exists"

    log_info "Checking if already migrated..."
    if $SSH_CMD "test -d '$SITE_PATH/releases'" 2>/dev/null; then
        log_error "Site appears to already be migrated (releases directory exists)"
    fi
    log_success "Site not yet migrated"

    log_info "Checking for Laravel installation..."
    if ! $SSH_CMD "test -f '$SITE_PATH/artisan'" 2>/dev/null; then
        log_warning "artisan file not found - this may not be a Laravel installation"
        if ! confirm "Continue anyway?"; then
            exit 0
        fi
    else
        log_success "Laravel installation detected"
    fi

    # Auto-detect database credentials from .env if not provided
    if [[ "$SKIP_DB_BACKUP" != "true" ]]; then
        log_info "Reading database credentials from .env..."
        [[ -z "$DB_NAME" ]] && DB_NAME=$(get_env_value "DB_DATABASE")
        [[ -z "$DB_USER" ]] && DB_USER=$(get_env_value "DB_USERNAME")
        [[ -z "$DB_PASS" ]] && DB_PASS=$(get_env_value "DB_PASSWORD")

        if [[ -z "$DB_NAME" || -z "$DB_USER" ]]; then
            log_warning "Could not detect database credentials from .env"
            if ! confirm "Skip database backup and continue?"; then
                exit 0
            fi
            SKIP_DB_BACKUP=true
        else
            log_success "Database credentials detected: $DB_NAME (user: $DB_USER)"
        fi
    fi

    log_success "All pre-flight checks passed"
}

# =============================================================================
# Backup Project Files
# =============================================================================
backup_project() {
    log_step "Step 2/5: Backup Project Files"

    BACKUP_FILE="${DOMAIN}-files-${TIMESTAMP}.tar.gz"
    BACKUP_FULL_PATH="${BACKUP_PATH}/${BACKUP_FILE}"

    log_info "Creating backup directory: $BACKUP_PATH"
    remote_sudo "mkdir -p '$BACKUP_PATH'"

    log_info "Creating project backup: $BACKUP_FILE"
    log_info "This may take a few minutes for large sites..."

    if [[ "$DRY_RUN" != "true" ]]; then
        # Create tarball excluding vendor, node_modules, and .git for speed
        # Note: Hidden files like .env ARE included in the backup
        remote_sudo "cd '$BASE_PATH' && tar -czf '$BACKUP_FULL_PATH' \
            --exclude='${DOMAIN}/vendor' \
            --exclude='${DOMAIN}/node_modules' \
            --exclude='${DOMAIN}/.git' \
            --exclude='${DOMAIN}/storage/logs/*.log' \
            '${DOMAIN}'"

        # Verify backup was created
        if ! $SSH_CMD "test -f '$BACKUP_FULL_PATH'" 2>/dev/null; then
            log_error "Project backup failed - file not created"
        fi

        # Get backup size
        BACKUP_SIZE=$($SSH_CMD "ls -lh '$BACKUP_FULL_PATH' | awk '{print \$5}'" 2>/dev/null)
        log_success "Project backup created: $BACKUP_FULL_PATH ($BACKUP_SIZE)"
    else
        log_success "Project backup would be created: $BACKUP_FULL_PATH"
    fi
}

# =============================================================================
# Backup Database
# =============================================================================
backup_database() {
    log_step "Step 3/5: Backup Database"

    if [[ "$SKIP_DB_BACKUP" == "true" ]]; then
        log_warning "Database backup skipped (--skip-db-backup flag)"
        return 0
    fi

    DB_BACKUP_FILE="${DOMAIN}-database-${TIMESTAMP}.sql.gz"
    DB_BACKUP_FULL_PATH="${BACKUP_PATH}/${DB_BACKUP_FILE}"

    log_info "Creating database backup: $DB_BACKUP_FILE"

    if [[ "$DRY_RUN" != "true" ]]; then
        # Create database dump
        remote_exec "mysqldump -u'${DB_USER}' -p'${DB_PASS}' '${DB_NAME}' 2>/dev/null | gzip > '${DB_BACKUP_FULL_PATH}'"

        # Verify backup was created and is not empty
        if ! $SSH_CMD "test -s '$DB_BACKUP_FULL_PATH'" 2>/dev/null; then
            log_error "Database backup failed - file is empty or not created"
        fi

        # Get backup size
        DB_BACKUP_SIZE=$($SSH_CMD "ls -lh '$DB_BACKUP_FULL_PATH' | awk '{print \$5}'" 2>/dev/null)
        log_success "Database backup created: $DB_BACKUP_FULL_PATH ($DB_BACKUP_SIZE)"
    else
        log_success "Database backup would be created: $DB_BACKUP_FULL_PATH"
    fi
}

# =============================================================================
# Migrate Directory Structure
# =============================================================================
migrate_structure() {
    log_step "Step 4/5: Migrate Directory Structure"

    log_info "Creating laravel-deployer directory structure..."

    # Create directories
    remote_sudo "mkdir -p '$SITE_PATH/releases/$RELEASE_NAME'"
    remote_sudo "mkdir -p '$SITE_PATH/shared/storage/app/public'"
    remote_sudo "mkdir -p '$SITE_PATH/shared/storage/framework/cache/data'"
    remote_sudo "mkdir -p '$SITE_PATH/shared/storage/framework/sessions'"
    remote_sudo "mkdir -p '$SITE_PATH/shared/storage/framework/views'"
    remote_sudo "mkdir -p '$SITE_PATH/shared/storage/logs'"
    remote_sudo "mkdir -p '$SITE_PATH/.dep'"

    log_success "Directory structure created"

    log_info "Moving Laravel files to release: $RELEASE_NAME"

    # Move Laravel directories and files
    LARAVEL_ITEMS="app bootstrap config database lang public resources routes vendor artisan composer.json composer.lock"
    for item in $LARAVEL_ITEMS; do
        remote_sudo "if [ -e '$SITE_PATH/$item' ]; then mv '$SITE_PATH/$item' '$SITE_PATH/releases/$RELEASE_NAME/'; fi"
    done

    # Move any PHP files in root
    remote_sudo "mv '$SITE_PATH'/*.php '$SITE_PATH/releases/$RELEASE_NAME/' 2>/dev/null || true"

    log_success "Files moved to release"

    log_info "Setting up shared storage..."

    # Copy storage to shared (preserve data)
    remote_sudo "if [ -d '$SITE_PATH/releases/$RELEASE_NAME/storage' ]; then cp -an '$SITE_PATH/releases/$RELEASE_NAME/storage/'* '$SITE_PATH/shared/storage/' 2>/dev/null || true; fi"
    remote_sudo "rm -rf '$SITE_PATH/releases/$RELEASE_NAME/storage'"

    # Move .env to shared
    remote_sudo "if [ -f '$SITE_PATH/releases/$RELEASE_NAME/.env' ]; then mv '$SITE_PATH/releases/$RELEASE_NAME/.env' '$SITE_PATH/shared/.env'; fi"

    # Create symlinks
    remote_sudo "ln -sfn '$SITE_PATH/shared/storage' '$SITE_PATH/releases/$RELEASE_NAME/storage'"
    remote_sudo "ln -sfn '$SITE_PATH/shared/.env' '$SITE_PATH/releases/$RELEASE_NAME/.env'"

    log_success "Shared storage configured"

    log_info "Creating current symlink..."
    remote_sudo "ln -sfn '$SITE_PATH/releases/$RELEASE_NAME' '$SITE_PATH/current'"

    log_success "Current symlink created: current -> releases/$RELEASE_NAME"
}

# =============================================================================
# Set Permissions
# =============================================================================
set_permissions() {
    log_step "Step 5/5: Set Permissions"

    log_info "Setting ownership and permissions..."

    # Set ownership
    remote_sudo "chown -R '$DEPLOY_USER:$WEB_USER' '$SITE_PATH'"

    # Storage needs to be writable by web server
    remote_sudo "chmod -R 775 '$SITE_PATH/shared/storage'"
    remote_sudo "chown -R '$WEB_USER:$WEB_USER' '$SITE_PATH/shared/storage'"

    # .dep directory for deployer
    remote_sudo "chown '$DEPLOY_USER:$WEB_USER' '$SITE_PATH/.dep'"

    log_success "Permissions set (deploy_user: $DEPLOY_USER, web_user: $WEB_USER)"
}

# =============================================================================
# Print Summary
# =============================================================================
print_summary() {
    echo ""
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${GREEN}  Migration Completed Successfully!${NC}"
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    echo -e "${CYAN}Final Structure:${NC}"
    echo ""
    echo "$SITE_PATH/"
    echo "├── current -> releases/$RELEASE_NAME"
    echo "├── releases/"
    echo "│   └── $RELEASE_NAME/"
    echo "│       ├── app/"
    echo "│       ├── public/"
    echo "│       ├── storage -> ../shared/storage"
    echo "│       ├── .env -> ../shared/.env"
    echo "│       └── ..."
    echo "├── shared/"
    echo "│   ├── storage/"
    echo "│   └── .env"
    echo "└── .dep/"
    echo ""
    echo -e "${CYAN}Backups:${NC}"
    echo "  Project:  ${BACKUP_PATH}/${DOMAIN}-files-${TIMESTAMP}.tar.gz"
    [[ "$SKIP_DB_BACKUP" != "true" ]] && echo "  Database: ${BACKUP_PATH}/${DOMAIN}-database-${TIMESTAMP}.sql.gz"
    echo ""
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}  IMPORTANT: Update Nginx Configuration${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    echo "  Change document root in your nginx config:"
    echo ""
    echo "    # FROM:"
    echo "    root ${SITE_PATH}/public;"
    echo ""
    echo "    # TO:"
    echo "    root ${SITE_PATH}/current/public;"
    echo ""
    echo "  Then reload nginx:"
    echo "    sudo nginx -t && sudo systemctl reload nginx"
    echo ""
    echo -e "${CYAN}Next Steps:${NC}"
    echo "  1. Update nginx configuration (see above)"
    echo "  2. Update .deploy/.env.* files with server credentials"
    echo "  3. Test deployment: php artisan deploy staging --dry-run"
    echo ""
}

# =============================================================================
# Main Execution
# =============================================================================
main() {
    echo ""
    echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${CYAN}  Laravel Deployer Migration Script${NC}"
    echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    echo "  Server:       ${SSH_USER}@${HOST}:${SSH_PORT}"
    echo "  Domain:       $DOMAIN"
    echo "  Site Path:    $SITE_PATH"
    echo "  Release Name: $RELEASE_NAME"
    echo "  Deploy User:  $DEPLOY_USER"
    echo "  Web User:     $WEB_USER"
    [[ "$DRY_RUN" == "true" ]] && echo -e "  Mode:         ${YELLOW}DRY-RUN${NC}"
    echo ""
    echo -e "${YELLOW}  ⚠ Prerequisites:${NC}"
    echo "    - Traditional deployment structure (${SITE_PATH}/public)"
    echo "    - Nginx config pointing to ${SITE_PATH}/public"
    echo "    - NOT already using releases/current symlinks"
    echo ""

    if ! confirm "Proceed with migration?"; then
        log_info "Migration cancelled"
        exit 0
    fi

    preflight_checks
    backup_project
    backup_database
    migrate_structure
    set_permissions
    print_summary
}

# Run main function
main
