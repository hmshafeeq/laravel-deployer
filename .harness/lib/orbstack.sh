#!/bin/bash

# =============================================================================
# OrbStack VM helper library
# =============================================================================

orb_vm_exists() {
    local vm_name="$1"
    orbctl list -q | grep -Fxq "$vm_name"
}

orb_vm_create() {
    local vm_name="$1"
    local distro="$2"
    local vm_user="$3"
    local vm_arch="${4:-}"

    local args=(create)

    if [ -n "$vm_arch" ]; then
        args+=(--arch "$vm_arch")
    fi

    if [ -n "$vm_user" ]; then
        args+=(--user "$vm_user")
    fi

    args+=("$distro" "$vm_name")

    orbctl "${args[@]}"
}

orb_vm_start() {
    local vm_name="$1"
    orbctl start "$vm_name" >/dev/null
}

orb_vm_delete() {
    local vm_name="$1"
    orbctl delete -f "$vm_name" >/dev/null
}

orb_vm_wait_ready() {
    local vm_name="$1"
    local max_attempts="${2:-60}"
    local attempt=0

    while [ "$attempt" -lt "$max_attempts" ]; do
        if orbctl run -m "$vm_name" -u root bash -lc 'echo ready' >/dev/null 2>&1; then
            return 0
        fi

        attempt=$((attempt + 1))
        sleep 2
    done

    return 1
}

orb_vm_info_json() {
    local vm_name="$1"
    orbctl info "$vm_name" -f json
}

orb_vm_ip() {
    local vm_name="$1"
    orb_vm_info_json "$vm_name" | php -r '$data=json_decode(stream_get_contents(STDIN), true); if (!is_array($data)) { exit(1);} echo $data["ip4"] ?? "";'
}

orb_vm_exec_root() {
    local vm_name="$1"
    local command="$2"
    orbctl run -m "$vm_name" -u root bash -lc "$command"
}

orb_vm_exec_user() {
    local vm_name="$1"
    local vm_user="$2"
    local command="$3"
    orbctl run -m "$vm_name" -u "$vm_user" bash -lc "$command"
}

orb_vm_push_user() {
    local vm_name="$1"
    local source_path="$2"
    local destination_rel="$3"
    orbctl push -m "$vm_name" "$source_path" "$destination_rel"
}

orb_vm_logs() {
    local vm_name="$1"
    orbctl logs "$vm_name"
}
