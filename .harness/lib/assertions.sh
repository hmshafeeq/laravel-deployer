#!/bin/bash

# =============================================================================
# Test assertion library for OrbStack VM integration tests
# =============================================================================

PASS_COUNT=0
FAIL_COUNT=0
CURRENT_SECTION=""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------

section() {
    CURRENT_SECTION="$1"
    echo ""
    echo -e "${BOLD}${BLUE}=== $1 ===${NC}"
    echo ""
}

step() {
    echo -e "${YELLOW}  -> $1${NC}"
}

note() {
    echo "     $1"
}

pass() {
    PASS_COUNT=$((PASS_COUNT + 1))
    echo -e "  ${GREEN}[PASS]${NC} $1"
}

fail() {
    FAIL_COUNT=$((FAIL_COUNT + 1))
    echo -e "  ${RED}[FAIL]${NC} $1"
    if [ -n "${2:-}" ]; then
        echo -e "         ${RED}$2${NC}"
    fi
}

summary() {
    local total=$((PASS_COUNT + FAIL_COUNT))
    echo ""
    echo -e "${BOLD}============================================${NC}"
    if [ "$FAIL_COUNT" -eq 0 ]; then
        echo -e "${GREEN}RESULTS: $PASS_COUNT passed, 0 failed (${total} total)${NC}"
    else
        echo -e "${RED}RESULTS: $PASS_COUNT passed, $FAIL_COUNT failed (${total} total)${NC}"
    fi
    echo -e "${BOLD}============================================${NC}"
    echo ""
    return $(( FAIL_COUNT > 0 ? 1 : 0 ))
}

# ---------------------------------------------------------------------------
# SSH helpers
# ---------------------------------------------------------------------------

SSH_KEY=""

ssh_cmd() {
    local host="$1"
    local cmd="$2"
    local port="${3:-22}"
    local wrapped_cmd="export LC_ALL=C LANG=C; ${cmd}"

    LC_ALL=C LANG=C ssh -o StrictHostKeyChecking=no \
        -o UserKnownHostsFile=/dev/null \
        -o LogLevel=ERROR \
        -o ConnectTimeout=5 \
        -i "$SSH_KEY" \
        -p "$port" \
        deploy@"$host" \
        "$wrapped_cmd" 2>&1
}

wait_for_ssh() {
    local host="$1"
    local port="${2:-22}"
    local max_attempts="${3:-60}"
    local attempt=0

    while [ "$attempt" -lt "$max_attempts" ]; do
        if ssh -o StrictHostKeyChecking=no \
               -o UserKnownHostsFile=/dev/null \
               -o LogLevel=ERROR \
               -o ConnectTimeout=3 \
               -i "$SSH_KEY" \
               -p "$port" \
               deploy@"$host" \
               "echo ready" >/dev/null 2>&1; then
            return 0
        fi
        attempt=$((attempt + 1))
        sleep 2
    done

    return 1
}

wait_for_http() {
    local url="$1"
    local max_attempts="${2:-30}"
    local expected_status="${3:-200}"
    local attempt=0

    while [ "$attempt" -lt "$max_attempts" ]; do
        if curl -s -o /dev/null -w "%{http_code}" "$url" 2>/dev/null | grep -q "^${expected_status}$"; then
            return 0
        fi
        attempt=$((attempt + 1))
        sleep 2
    done
    return 1
}

# ---------------------------------------------------------------------------
# Assertions
# ---------------------------------------------------------------------------

assert_equal() {
    local actual="$1"
    local expected="$2"
    local label="${3:-Values match}"

    if [ "$actual" = "$expected" ]; then
        pass "$label"
    else
        fail "$label" "Expected '$expected', got '$actual'"
    fi
}

assert_gte() {
    local actual="$1"
    local minimum="$2"
    local label="${3:-Value is >= minimum}"

    if [ "$actual" -ge "$minimum" ]; then
        pass "$label"
    else
        fail "$label" "Expected >= $minimum, got $actual"
    fi
}

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local label="${3:-Output contains expected string}"

    if echo "$haystack" | grep -q "$needle"; then
        pass "$label"
    else
        fail "$label" "Expected output to contain '$needle'"
    fi
}

assert_not_contains() {
    local haystack="$1"
    local needle="$2"
    local label="${3:-Output does not contain string}"

    if ! echo "$haystack" | grep -q "$needle"; then
        pass "$label"
    else
        fail "$label" "Expected output NOT to contain '$needle'"
    fi
}

assert_ssh() {
    local host="$1"
    local cmd="$2"
    local expected_exit="${3:-0}"
    local port="${4:-22}"
    local label="${5:-Remote command succeeded}"

    local output
    output=$(ssh_cmd "$host" "$cmd" "$port")
    local actual=$?

    if [ "$actual" -eq "$expected_exit" ]; then
        pass "$label"
    else
        fail "$label" "Expected exit code $expected_exit, got $actual. Output: $output"
    fi
}

assert_http() {
    local url="$1"
    local expected_status="${2:-200}"
    local label="${3:-HTTP check}"

    local actual
    actual=$(curl -s -o /dev/null -w "%{http_code}" "$url" 2>/dev/null || true)

    if [ "$actual" = "$expected_status" ]; then
        pass "$label"
    else
        fail "$label" "Expected HTTP $expected_status, got $actual"
    fi
}

assert_file_exists() {
    local path="$1"
    local label="${2:-File exists}"

    if [ -f "$path" ]; then
        pass "$label"
    else
        fail "$label" "Missing file: $path"
    fi
}

# ---------------------------------------------------------------------------
# Artisan helper
# ---------------------------------------------------------------------------

run_artisan() {
    local cmd="$1"
    local app_dir="$2"

    (cd "$app_dir" && php artisan $cmd 2>&1)
}
