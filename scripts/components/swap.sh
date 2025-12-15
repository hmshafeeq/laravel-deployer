#!/bin/bash

################################################################################
# Swap Space Configuration
################################################################################

setup_swap() {
    local swap_size=${1:-2G}

    print_info "Setting up swap space ($swap_size)..."

    # Check if swap already exists
    if swapon --show | grep -q "/swapfile"; then
        print_warning "Swap file already exists"
        return 0
    fi

    # Convert size to MB (remove G and multiply by 1024)
    local size_mb=$(echo "$swap_size" | sed 's/G$//' | awk '{print $1 * 1024}')

    # Create swap file
    print_info "Creating swap file..."
    fallocate -l "${swap_size}" /swapfile

    # If fallocate fails, use dd
    if [ $? -ne 0 ]; then
        print_info "Using dd to create swap file..."
        dd if=/dev/zero of=/swapfile bs=1M count="$size_mb" status=none
    fi

    # Set correct permissions
    chmod 600 /swapfile
    print_success "Swap file created"

    # Setup swap
    print_info "Setting up swap..."
    mkswap /swapfile >/dev/null 2>&1
    swapon /swapfile

    # Make swap permanent
    if ! grep -q "/swapfile" /etc/fstab; then
        echo "/swapfile none swap sw 0 0" >> /etc/fstab
    fi

    print_success "Swap enabled"

    # Configure swappiness
    configure_swappiness

    # Display swap info
    print_info "Swap information:"
    swapon --show
}

configure_swappiness() {
    print_info "Configuring swappiness..."

    # Set swappiness to 10 (default is 60)
    # Lower values make the system use swap less aggressively
    sysctl vm.swappiness=10 >/dev/null 2>&1

    # Make it permanent
    if ! grep -q "vm.swappiness" /etc/sysctl.conf; then
        echo "vm.swappiness=10" >> /etc/sysctl.conf
    else
        sed -i 's/^vm.swappiness=.*/vm.swappiness=10/' /etc/sysctl.conf
    fi

    # Configure cache pressure
    sysctl vm.vfs_cache_pressure=50 >/dev/null 2>&1

    if ! grep -q "vm.vfs_cache_pressure" /etc/sysctl.conf; then
        echo "vm.vfs_cache_pressure=50" >> /etc/sysctl.conf
    else
        sed -i 's/^vm.vfs_cache_pressure=.*/vm.vfs_cache_pressure=50/' /etc/sysctl.conf
    fi

    print_success "Swappiness configured"
}

# Remove swap
remove_swap() {
    print_info "Removing swap..."

    # Turn off swap
    swapoff /swapfile 2>/dev/null

    # Remove swap file
    rm -f /swapfile

    # Remove from fstab
    sed -i '/\/swapfile/d' /etc/fstab

    print_success "Swap removed"
}

# Check swap status
check_swap_status() {
    print_info "Swap Status:"
    swapon --show

    if [ $? -eq 0 ]; then
        echo ""
        print_info "Memory Information:"
        free -h
    fi
}

# Resize swap
resize_swap() {
    local new_size=$1

    print_info "Resizing swap to $new_size..."

    # Remove existing swap
    remove_swap

    # Create new swap with new size
    setup_swap "$new_size"

    print_success "Swap resized to $new_size"
}
