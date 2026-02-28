#!/bin/bash
set -euo pipefail

# =============================================================================
# OrbStack VM integration test runner for Laravel Deployer
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIR="$SCRIPT_DIR/lib"
FIXTURES_DIR="$SCRIPT_DIR/fixtures"
SCRIPTS_DIR="$SCRIPT_DIR/scripts"
PACKAGE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_DIR="$SCRIPT_DIR/laravel-app"
ARTIFACTS_ROOT="$PACKAGE_DIR/.harness/artifacts/orbstack"

RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_STARTED_AT_UTC="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
RUN_STARTED_EPOCH="$(date -u +%s)"
RUN_ARTIFACT_DIR="$ARTIFACTS_ROOT/$RUN_ID"
LATEST_ARTIFACT_LINK="$ARTIFACTS_ROOT/latest"
RESULTS_JSON="$RUN_ARTIFACT_DIR/results.json"
BOOTSTRAP_LOG_DIR="$RUN_ARTIFACT_DIR/bootstrap"

SCENARIO_ARG="all"
SELECTED_SCENARIOS=()
SCENARIO_RESULTS=()
CONSUMER_RESULTS=()
CONSUMING_PROJECTS=()

CLEAN_APP=1
KEEP_VM=0
REUSE_VM=0
CONSUMING_ONLY=0
OVERALL_EXIT=0

ORBSTACK_DISTRO="${ORBSTACK_DISTRO:-ubuntu:jammy}"
ORBSTACK_VM_ARCH="${ORBSTACK_VM_ARCH:-}"
ORBSTACK_VM_USER="${ORBSTACK_VM_USER:-$(id -un)}"
ORBSTACK_VM_NAME_PREFIX="${ORBSTACK_VM_NAME_PREFIX:-deployer-orb}"
ORBSTACK_PHP_VERSION="${ORBSTACK_PHP_VERSION:-8.4}"
ORBSTACK_NODE_VERSION="${ORBSTACK_NODE_VERSION:-20}"
SSH_PORT=22

ACTIVE_VMS=()
CREATED_VMS=()
CURRENT_SCENARIO_VM_NAME=""
CURRENT_SCENARIO_HOST=""

source "$LIB_DIR/assertions.sh"
source "$LIB_DIR/orbstack.sh"

usage() {
    cat <<USAGE
Usage: .harness/run-tests.sh [options]

Options:
  --scenario <name>            all|fresh|existing|drifted|drift|comma,list
  --clean                      Recreate .harness/laravel-app (default)
  --reuse-app                  Reuse existing test app
  --reuse                      Reuse scenario VM names and keep VMs after run
  --keep-vm                    Keep VM(s) after run for debugging
  --distro <distro:version>    OrbStack distro image (default: ubuntu:jammy)
  --arch <arch>                OrbStack machine architecture (arm64|amd64)
  --vm-user <username>         OrbStack default VM user (default: current macOS user)
  --vm-prefix <prefix>         VM name prefix (default: deployer-orb)
  --consuming-project <path>   Add downstream project path (repeatable)
  --consuming-projects-file <file>
                               File with one downstream project path per line
  --consuming-only             Run only downstream project checks
  --help                       Show this help
USAGE
}

slugify() {
    echo "$1" | tr '[:upper:]' '[:lower:]' | tr ' /:()' '_____' | tr -cd 'a-z0-9._-'
}

require_command() {
    local cmd="$1"
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "ERROR: Required command not found: $cmd"
        exit 1
    fi
}

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --scenario)
                SCENARIO_ARG="${2:-}"
                shift 2
                ;;
            --clean)
                CLEAN_APP=1
                shift
                ;;
            --reuse-app)
                CLEAN_APP=0
                shift
                ;;
            --reuse)
                REUSE_VM=1
                shift
                ;;
            --keep-vm)
                KEEP_VM=1
                shift
                ;;
            --distro)
                ORBSTACK_DISTRO="${2:-}"
                shift 2
                ;;
            --arch)
                ORBSTACK_VM_ARCH="${2:-}"
                shift 2
                ;;
            --vm-user)
                ORBSTACK_VM_USER="${2:-}"
                shift 2
                ;;
            --vm-prefix)
                ORBSTACK_VM_NAME_PREFIX="${2:-}"
                shift 2
                ;;
            --consuming-project)
                CONSUMING_PROJECTS+=("${2:-}")
                shift 2
                ;;
            --consuming-projects-file)
                load_consuming_projects_from_file "${2:-}"
                shift 2
                ;;
            --consuming-only)
                CONSUMING_ONLY=1
                shift
                ;;
            --help|-h)
                usage
                exit 0
                ;;
            *)
                echo "Unknown option: $1"
                usage
                exit 1
                ;;
        esac
    done
}

load_consuming_projects_from_file() {
    local file_path="$1"

    if [ -z "$file_path" ] || [ ! -f "$file_path" ]; then
        echo "Consuming projects file not found: $file_path"
        exit 1
    fi

    while IFS= read -r line; do
        local trimmed
        trimmed="$(echo "$line" | sed 's/#.*$//' | xargs)"
        if [ -n "$trimmed" ]; then
            CONSUMING_PROJECTS+=("$trimmed")
        fi
    done < "$file_path"
}

normalize_scenario_name() {
    local scenario="$1"

    case "$scenario" in
        drift)
            echo "drifted"
            ;;
        *)
            echo "$scenario"
            ;;
    esac
}

resolve_scenarios() {
    if [ "$CONSUMING_ONLY" -eq 1 ]; then
        SELECTED_SCENARIOS=()
        return
    fi

    if [ "$SCENARIO_ARG" = "all" ]; then
        SELECTED_SCENARIOS=(fresh existing drifted)
        return
    fi

    IFS=',' read -r -a raw <<< "$SCENARIO_ARG"
    for item in "${raw[@]}"; do
        local normalized
        normalized="$(normalize_scenario_name "$item")"

        case "$normalized" in
            fresh|existing|drifted)
                SELECTED_SCENARIOS+=("$normalized")
                ;;
            *)
                echo "Invalid scenario: $item"
                echo "Valid scenarios: fresh, existing, drifted, drift, all"
                exit 1
                ;;
        esac
    done

    if [ "${#SELECTED_SCENARIOS[@]}" -eq 0 ]; then
        echo "No scenarios selected"
        exit 1
    fi
}

prepare_artifacts() {
    mkdir -p "$RUN_ARTIFACT_DIR/scenarios" "$RUN_ARTIFACT_DIR/consuming-projects" "$BOOTSTRAP_LOG_DIR"
    ln -sfn "$RUN_ARTIFACT_DIR" "$LATEST_ARTIFACT_LINK"
}

print_run_header() {
    echo ""
    echo "============================================"
    echo " Laravel Deployer — OrbStack VM Integration"
    echo "============================================"
    echo ""
    echo "Run ID: $RUN_ID"
    if [ "${#SELECTED_SCENARIOS[@]}" -gt 0 ]; then
        echo "Scenarios: ${SELECTED_SCENARIOS[*]}"
    else
        echo "Scenarios: (skipped)"
    fi
    echo "Artifacts: $RUN_ARTIFACT_DIR"
    echo "OrbStack Distro: $ORBSTACK_DISTRO"
    echo "OrbStack User: $ORBSTACK_VM_USER"
    echo ""
}

scenario_vm_name() {
    local scenario="$1"

    if [ "$REUSE_VM" -eq 1 ]; then
        echo "${ORBSTACK_VM_NAME_PREFIX}-${scenario}"
    else
        echo "${ORBSTACK_VM_NAME_PREFIX}-${RUN_ID}-${scenario}"
    fi
}

vm_is_created_this_run() {
    local vm_name="$1"

    for vm in "${CREATED_VMS[@]:-}"; do
        if [ "$vm" = "$vm_name" ]; then
            return 0
        fi
    done

    return 1
}

cleanup_on_exit() {
    local vm

    if [ "$KEEP_VM" -eq 1 ] || [ "$REUSE_VM" -eq 1 ]; then
        return
    fi

    for vm in "${CREATED_VMS[@]:-}"; do
        orb_vm_delete "$vm" >/dev/null 2>&1 || true
    done
}

trap cleanup_on_exit EXIT

generate_ssh_keys() {
    local ssh_dir="$SCRIPT_DIR/ssh"

    step "Generating SSH keys..."
    mkdir -p "$ssh_dir"

    if [ ! -f "$ssh_dir/id_ed25519" ]; then
        ssh-keygen -t ed25519 -f "$ssh_dir/id_ed25519" -N "" -q
    fi

    SSH_KEY="$ssh_dir/id_ed25519"
    export SSH_KEY
}

ensure_health_route() {
    local routes_file="$APP_DIR/routes/web.php"

    if [ -f "$routes_file" ] && ! grep -q "Route::get('/up'" "$routes_file"; then
        cat >> "$routes_file" <<'PHP'

Route::get('/up', function () {
    return response('OK', 200);
});
PHP
    fi
}

validate_policy_file() {
    local file_path="$1"
    local label="$2"
    local violations

    if [ ! -f "$file_path" ]; then
        fail "$label" "Missing file: $file_path"
        return 1
    fi

    set +e
    violations=$(php -r '
        $file = $argv[1];
        $json = json_decode(file_get_contents($file), true);
        if (!is_array($json)) {
            fwrite(STDERR, "invalid_json");
            exit(2);
        }
        $hooks = $json["beforeSymlink"] ?? [];
        if (!is_array($hooks)) {
            fwrite(STDERR, "invalid_before_symlink");
            exit(3);
        }

        $blocked = ["optimize", "optimize:clear", "config:cache", "route:cache", "view:cache"];
        $violations = [];

        foreach ($hooks as $hook) {
            $line = strtolower((string) $hook);
            foreach ($blocked as $term) {
                if (strpos($line, $term) !== false) {
                    $violations[] = $hook;
                    break;
                }
            }
        }

        echo implode("\n", $violations);
    ' "$file_path")
    local policy_exit=$?
    set -e

    if [ "$policy_exit" -ne 0 ]; then
        fail "$label" "Invalid deploy.json structure in $file_path"
        return 1
    fi

    if [ -n "$violations" ]; then
        fail "$label" "Forbidden beforeSymlink hooks found: $violations"
        return 1
    fi

    pass "$label"
    return 0
}

write_env_staging() {
    local host="$1"
    local port="$2"

    mkdir -p "$APP_DIR/.deploy"
    cat > "$APP_DIR/.deploy/.env.staging" <<EOF_ENV
DEPLOY_HOST=$host
DEPLOY_USER=deploy
DEPLOY_PATH=/var/www/staging
DEPLOY_IDENTITY_FILE=$SSH_KEY
DEPLOY_PORT=$port
EOF_ENV
}

install_deployer_package() {
    local log_file="$BOOTSTRAP_LOG_DIR/composer-deployer-install.log"
    local mirror_package_dir="$APP_DIR/packages/laravel-deployer"
    local vendor_package_dir="$APP_DIR/vendor/shaf/laravel-deployer"

    step "Installing local laravel-deployer package..."

    (
        mkdir -p "$APP_DIR/packages"
        rm -rf "$mirror_package_dir"
        mkdir -p "$mirror_package_dir"
        rsync -a --delete \
            --exclude '.git' \
            --exclude 'vendor' \
            --exclude '.harness' \
            --exclude '.agents' \
            "$PACKAGE_DIR/" "$mirror_package_dir/"
    ) >> "$log_file" 2>&1

    (
        cd "$APP_DIR"
        composer config --unset repositories.deployer-local || true
        composer config repositories.deployer-local '{"type": "path", "url": "packages/laravel-deployer", "options": {"symlink": false}}'
        composer config minimum-stability dev
        composer config prefer-stable true
    ) >> "$log_file" 2>&1

    if [ -f "$vendor_package_dir/composer.json" ]; then
        (
            cd "$APP_DIR"
            composer update shaf/laravel-deployer --no-interaction --prefer-dist
        ) >> "$log_file" 2>&1
    else
        (
            cd "$APP_DIR"
            composer require shaf/laravel-deployer:@dev --no-interaction --prefer-dist
        ) >> "$log_file" 2>&1
    fi

    if [ ! -f "$vendor_package_dir/composer.json" ]; then
        fail "Install local laravel-deployer package" "See log: $log_file"
        exit 1
    fi

    (
        rsync -a --delete \
            --exclude '.git' \
            --exclude 'vendor' \
            --exclude '.harness' \
            --exclude '.agents' \
            "$PACKAGE_DIR/" "$vendor_package_dir/"
    ) >> "$log_file" 2>&1
}

create_test_app() {
    local app_parent app_name
    local new_log="$BOOTSTRAP_LOG_DIR/laravel-new.log"
    local composer_install_log="$BOOTSTRAP_LOG_DIR/composer-install.log"
    local npm_install_log="$BOOTSTRAP_LOG_DIR/npm-install.log"

    app_parent="$(dirname "$APP_DIR")"
    app_name="$(basename "$APP_DIR")"

    if [ "$CLEAN_APP" -eq 1 ]; then
        step "Recreating Laravel test app (--clean)..."
        rm -rf "$APP_DIR"
    fi

    if [ ! -f "$APP_DIR/artisan" ]; then
        step "Creating Laravel test app..."
        if command -v laravel >/dev/null 2>&1; then
            (
                cd "$app_parent"
                laravel new "$app_name" --force --no-interaction --pest --database=sqlite
            ) > "$new_log" 2>&1
        else
            note "Laravel installer not found. Using composer create-project fallback."
            (
                cd "$app_parent"
                composer create-project laravel/laravel "$app_name" --no-interaction
            ) > "$new_log" 2>&1
        fi
    else
        note "Reusing existing Laravel test app."
    fi

    if [ ! -f "$APP_DIR/artisan" ]; then
        fail "Create Laravel test app" "Expected $APP_DIR/artisan. See log: $new_log"
        exit 1
    fi

    mkdir -p "$APP_DIR/.deploy"
    cp "$FIXTURES_DIR/deploy/deploy.json" "$APP_DIR/.deploy/"

    if [ -f "$FIXTURES_DIR/2024_01_01_000000_create_tests_table.php" ]; then
        cp "$FIXTURES_DIR/2024_01_01_000000_create_tests_table.php" "$APP_DIR/database/migrations/"
    fi

    validate_policy_file "$APP_DIR/.deploy/deploy.json" "Fixture policy: no forbidden beforeSymlink hooks" || exit 1

    ensure_health_route
    install_deployer_package

    step "Installing test app dependencies..."
    (
        cd "$APP_DIR"
        composer install --no-interaction
    ) > "$composer_install_log" 2>&1

    if [ -f "$APP_DIR/package.json" ]; then
        step "Installing test app frontend dependencies..."
        if [ -f "$APP_DIR/package-lock.json" ]; then
            (
                cd "$APP_DIR"
                npm ci --no-audit --no-fund
            ) > "$npm_install_log" 2>&1
        else
            (
                cd "$APP_DIR"
                npm install --no-audit --no-fund
            ) > "$npm_install_log" 2>&1
        fi
    fi

    step "Initializing git repo for diff/sync tests..."
    (
        cd "$APP_DIR"
        if [ ! -d .git ]; then
            git init -q
        fi
        git config user.name "Laravel Deployer Tests"
        git config user.email "tests@local"
        git add -A
        if ! git diff --cached --quiet; then
            git commit -q -m "test baseline"
        fi
    )
}

ssh_opts() {
    local host="$1"
    local port="${2:-22}"

    echo "-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -i $SSH_KEY -p $port deploy@$host"
}

scp_to_host() {
    local host="$1"
    local src="$2"
    local dest="$3"

    scp -o StrictHostKeyChecking=no \
        -o UserKnownHostsFile=/dev/null \
        -o LogLevel=ERROR \
        -i "$SSH_KEY" \
        -P "$SSH_PORT" \
        "$src" \
        deploy@"$host":"$dest"
}

scp_from_host() {
    local host="$1"
    local src="$2"
    local dest="$3"

    scp -o StrictHostKeyChecking=no \
        -o UserKnownHostsFile=/dev/null \
        -o LogLevel=ERROR \
        -i "$SSH_KEY" \
        -P "$SSH_PORT" \
        deploy@"$host":"$src" \
        "$dest"
}

rsync_to_host() {
    local host="$1"
    local src="$2"
    local dest="$3"

    rsync -az --delete \
        -e "ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -i $SSH_KEY -p $SSH_PORT" \
        "$src" \
        deploy@"$host":"$dest"
}

run_artisan_expect_success() {
    local scenario="$1"
    local label="$2"
    local cmd="$3"
    local log_file="$RUN_ARTIFACT_DIR/scenarios/$scenario/$(slugify "$label").log"

    step "$label"
    if run_artisan "$cmd" "$APP_DIR" > "$log_file" 2>&1; then
        pass "$label"
        return 0
    fi

    fail "$label" "Command failed: php artisan $cmd (log: $log_file)"
    tail -n 40 "$log_file" || true
    return 1
}

run_artisan_expect_failure() {
    local scenario="$1"
    local label="$2"
    local cmd="$3"
    local log_file="$RUN_ARTIFACT_DIR/scenarios/$scenario/$(slugify "$label").log"

    step "$label"
    if run_artisan "$cmd" "$APP_DIR" > "$log_file" 2>&1; then
        fail "$label" "Expected failure but command succeeded (log: $log_file)"
        return 1
    fi

    pass "$label"
    return 0
}

run_artisan_capture() {
    local scenario="$1"
    local label="$2"
    local cmd="$3"
    local log_file="$RUN_ARTIFACT_DIR/scenarios/$scenario/$(slugify "$label").log"

    step "$label"
    if run_artisan "$cmd" "$APP_DIR" > "$log_file" 2>&1; then
        cat "$log_file"
        return 0
    fi

    cat "$log_file"
    return 1
}

run_ssh_expect_success() {
    local scenario="$1"
    local label="$2"
    local host="$3"
    local cmd="$4"
    local log_file="$RUN_ARTIFACT_DIR/scenarios/$scenario/$(slugify "$label").log"

    step "$label"
    if ssh_cmd "$host" "$cmd" "$SSH_PORT" > "$log_file" 2>&1; then
        pass "$label"
        return 0
    fi

    fail "$label" "Remote command failed on host $host (log: $log_file)"
    tail -n 40 "$log_file" || true
    return 1
}

wait_for_remote_http() {
    local host="$1"
    local path="$2"
    local max_attempts="${3:-30}"
    local expected_status="${4:-200}"
    local attempt=0

    while [ "$attempt" -lt "$max_attempts" ]; do
        if ssh_cmd "$host" "curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1${path}" "$SSH_PORT" 2>/dev/null | grep -q "^${expected_status}$"; then
            return 0
        fi

        attempt=$((attempt + 1))
        sleep 2
    done

    return 1
}

assert_remote_http() {
    local host="$1"
    local path="$2"
    local expected_status="${3:-200}"
    local label="${4:-Remote HTTP check}"

    local status
    status=$(ssh_cmd "$host" "curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1${path}" "$SSH_PORT" 2>/dev/null || true)

    if [ "$status" = "$expected_status" ]; then
        pass "$label"
    else
        fail "$label" "Expected HTTP $expected_status at $path, got $status"
    fi
}

bootstrap_vm_access() {
    local vm_name="$1"
    local scenario="$2"
    local bootstrap_log="$BOOTSTRAP_LOG_DIR/bootstrap-vm-${scenario}.log"

    orb_vm_push_user "$vm_name" "$SSH_KEY.pub" ".codex-deployer.pub" >> "$bootstrap_log" 2>&1
    orb_vm_push_user "$vm_name" "$SCRIPTS_DIR/bootstrap-vm.sh" ".codex-bootstrap-vm.sh" >> "$bootstrap_log" 2>&1

    orb_vm_exec_root "$vm_name" "chmod +x /home/$ORBSTACK_VM_USER/.codex-bootstrap-vm.sh && bash /home/$ORBSTACK_VM_USER/.codex-bootstrap-vm.sh '$ORBSTACK_VM_USER' '/home/$ORBSTACK_VM_USER/.codex-deployer.pub' deploy" >> "$bootstrap_log" 2>&1
}

setup_vm_for_scenario() {
    local scenario="$1"
    local vm_name
    local vm_ip
    local create_log="$BOOTSTRAP_LOG_DIR/orbstack-create-${scenario}.log"

    vm_name="$(scenario_vm_name "$scenario")"
    ACTIVE_VMS+=("$vm_name")

    if orb_vm_exists "$vm_name"; then
        if [ "$REUSE_VM" -eq 1 ]; then
            note "Reusing OrbStack VM: $vm_name"
            orb_vm_start "$vm_name" >> "$create_log" 2>&1 || true
        else
            step "Deleting stale VM: $vm_name"
            orb_vm_delete "$vm_name" >> "$create_log" 2>&1 || true
            step "Creating OrbStack VM: $vm_name"
            orb_vm_create "$vm_name" "$ORBSTACK_DISTRO" "$ORBSTACK_VM_USER" "$ORBSTACK_VM_ARCH" >> "$create_log" 2>&1
            CREATED_VMS+=("$vm_name")
        fi
    else
        step "Creating OrbStack VM: $vm_name"
        orb_vm_create "$vm_name" "$ORBSTACK_DISTRO" "$ORBSTACK_VM_USER" "$ORBSTACK_VM_ARCH" >> "$create_log" 2>&1
        CREATED_VMS+=("$vm_name")
    fi

    step "Waiting for OrbStack VM readiness: $vm_name"
    if ! orb_vm_wait_ready "$vm_name" 120; then
        fail "OrbStack VM readiness ($scenario)" "VM did not become ready: $vm_name"
        return 1
    fi

    step "Bootstrapping SSH/deploy user in VM: $vm_name"
    if ! bootstrap_vm_access "$vm_name" "$scenario"; then
        fail "Bootstrap VM access ($scenario)" "Failed to configure SSH/deploy user"
        return 1
    fi

    vm_ip="$(orb_vm_ip "$vm_name")"
    if [ -z "$vm_ip" ]; then
        fail "Resolve VM IP ($scenario)" "Unable to get IP for VM: $vm_name"
        return 1
    fi

    step "Waiting for SSH on $vm_ip:$SSH_PORT"
    if ! wait_for_ssh "$vm_ip" "$SSH_PORT" 90; then
        fail "SSH readiness ($scenario)" "SSH not ready on VM IP $vm_ip"
        return 1
    fi

    CURRENT_SCENARIO_VM_NAME="$vm_name"
    CURRENT_SCENARIO_HOST="$vm_ip"
    return 0
}

prepare_existing_vm_fixture() {
    local scenario="$1"
    local host="$2"
    local setup_log="$RUN_ARTIFACT_DIR/scenarios/$scenario/setup-existing.log"

    step "Syncing test app to VM for existing fixture"
    rsync_to_host "$host" "$APP_DIR/" "/tmp/test-app/" > "$setup_log" 2>&1

    step "Applying existing flat-server fixture"
    scp_to_host "$host" "$SCRIPTS_DIR/setup-existing.sh" "/tmp/setup-existing.sh" >> "$setup_log" 2>&1

    if ! ssh_cmd "$host" "chmod +x /tmp/setup-existing.sh && sudo PHP_VERSION=$ORBSTACK_PHP_VERSION bash /tmp/setup-existing.sh /tmp/test-app" "$SSH_PORT" >> "$setup_log" 2>&1; then
        fail "Prepare existing VM fixture" "See log: $setup_log"
        tail -n 40 "$setup_log" || true
        return 1
    fi

    pass "Prepare existing VM fixture"
    return 0
}

apply_drift_state() {
    local scenario="$1"
    local host="$2"
    local log_file="$RUN_ARTIFACT_DIR/scenarios/$scenario/apply-drift.log"

    step "Applying server drift mutations"

    scp_to_host "$host" "$SCRIPTS_DIR/mutate-existing-drift.sh" "/tmp/mutate-existing-drift.sh" > "$log_file" 2>&1

    if ssh_cmd "$host" "chmod +x /tmp/mutate-existing-drift.sh && sudo bash /tmp/mutate-existing-drift.sh" "$SSH_PORT" >> "$log_file" 2>&1; then
        pass "Apply drift"
        return 0
    fi

    fail "Apply drift" "Mutation script failed (log: $log_file)"
    tail -n 40 "$log_file" || true
    return 1
}

apply_generated_fix_script() {
    local scenario="$1"
    local host="$2"
    local local_fix_script="$APP_DIR/.deploy/fix-permissions-staging.sh"
    local log_file="$RUN_ARTIFACT_DIR/scenarios/$scenario/apply-generated-fix.log"

    assert_file_exists "$local_fix_script" "Fix script generated by diagnose --fix"
    if [ ! -f "$local_fix_script" ]; then
        return 1
    fi

    step "Applying generated fix script on VM"
    scp_to_host "$host" "$local_fix_script" "/tmp/fix-permissions-staging.sh" > "$log_file" 2>&1

    if ssh_cmd "$host" "sudo bash /tmp/fix-permissions-staging.sh" "$SSH_PORT" >> "$log_file" 2>&1; then
        pass "Apply generated fix script"
        return 0
    fi

    fail "Apply generated fix script" "Failed on host $host (log: $log_file)"
    tail -n 40 "$log_file" || true
    return 1
}

collect_vm_artifacts() {
    local scenario="$1"
    local vm_name="$2"
    local host="$3"
    local scenario_dir="$RUN_ARTIFACT_DIR/scenarios/$scenario"

    mkdir -p "$scenario_dir"

    orb_vm_info_json "$vm_name" > "$scenario_dir/orbstack-info.json" 2>/dev/null || true
    orb_vm_logs "$vm_name" > "$scenario_dir/orbstack-logs.txt" 2>/dev/null || true

    ssh_cmd "$host" "uname -a" "$SSH_PORT" > "$scenario_dir/remote-uname.log" 2>&1 || true
    ssh_cmd "$host" "ls -la /var/www/staging || true" "$SSH_PORT" > "$scenario_dir/remote-deploy-path.log" 2>&1 || true
    ssh_cmd "$host" "sudo systemctl status nginx --no-pager || true" "$SSH_PORT" > "$scenario_dir/remote-nginx-status.log" 2>&1 || true

    ssh_cmd "$host" "if [ -d /var/www/staging/.dep ]; then sudo tar -C /var/www/staging -czf /tmp/deployer-state.tgz .dep current releases shared 2>/dev/null || true; sudo chown deploy:deploy /tmp/deployer-state.tgz; fi" "$SSH_PORT" > "$scenario_dir/remote-snapshot-build.log" 2>&1 || true

    scp_from_host "$host" "/tmp/deployer-state.tgz" "$scenario_dir/deployer-state.tgz" >/dev/null 2>&1 || true
}

scenario_fresh() {
    local scenario="fresh"
    local host="$1"

    section "SCENARIO: Fresh Server Provision (OrbStack VM)"

    write_env_staging "$host" "$SSH_PORT"

    assert_ssh "$host" "echo ok" 0 "$SSH_PORT" "SSH connectivity"
    run_ssh_expect_success "$scenario" "Ensure deploy user belongs to www-data" "$host" "sudo usermod -a -G www-data deploy && id deploy | grep -q 'www-data'" || return 1

    run_artisan_expect_success "$scenario" "Provision fresh server" "deployer:server provision --host=$host --port=$SSH_PORT --user=deploy --deploy-user=deploy --key=$SSH_KEY --php-version=$ORBSTACK_PHP_VERSION --nodejs-version=$ORBSTACK_NODE_VERSION --no-firewall --no-swap --no-supervisor --non-interactive" || return 1

    assert_ssh "$host" "php -v | head -1 | grep -q '$ORBSTACK_PHP_VERSION'" 0 "$SSH_PORT" "PHP $ORBSTACK_PHP_VERSION installed"
    assert_ssh "$host" "nginx -v 2>&1 | grep -q 'nginx'" 0 "$SSH_PORT" "Nginx installed"
    assert_ssh "$host" "composer --version 2>&1 | grep -q 'Composer'" 0 "$SSH_PORT" "Composer installed"
    assert_ssh "$host" "node --version | grep -q 'v$ORBSTACK_NODE_VERSION'" 0 "$SSH_PORT" "Node.js $ORBSTACK_NODE_VERSION installed"
    run_ssh_expect_success "$scenario" "Disable Apache if present" "$host" "sudo systemctl stop apache2 >/dev/null 2>&1 || true; sudo systemctl disable apache2 >/dev/null 2>&1 || true" || return 1

    run_ssh_expect_success "$scenario" "Configure Nginx vhost" "$host" "sudo tee /etc/nginx/sites-available/staging > /dev/null <<'NGINX'
server {
    listen 80 default_server;
    server_name _;
    root /var/www/staging/current/public;

    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${ORBSTACK_PHP_VERSION}-fpm.sock;
    }
}
NGINX
sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -sf /etc/nginx/sites-available/staging /etc/nginx/sites-enabled/staging" || return 1

    run_ssh_expect_success "$scenario" "Start required services" "$host" "sudo mkdir -p /run/php && sudo systemctl restart php${ORBSTACK_PHP_VERSION}-fpm && sudo nginx -t && (sudo nginx -s reload || sudo systemctl reload nginx || true)" || return 1

    run_ssh_expect_success "$scenario" "Install MariaDB for backup coverage" "$host" "sudo apt-get update -o Acquire::Retries=5 && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server mariadb-client && (sudo systemctl enable --now mariadb || sudo systemctl enable --now mysql)" || return 1

    run_ssh_expect_success "$scenario" "Create database user for backup flow" "$host" "sudo mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS deployer_test;
CREATE USER IF NOT EXISTS 'deployer'@'localhost' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON deployer_test.* TO 'deployer'@'localhost';
FLUSH PRIVILEGES;
SQL" || return 1

    run_ssh_expect_success "$scenario" "Create shared .env" "$host" "sudo mkdir -p /var/www/staging/shared/database && sudo touch /var/www/staging/shared/database/database.sqlite && sudo chown -R deploy:www-data /var/www/staging && cat > /var/www/staging/shared/.env <<'ENV'
APP_NAME=\"Deployer Test\"
APP_ENV=staging
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=deployer_test
DB_USERNAME=deployer
DB_PASSWORD=secret

LOG_CHANNEL=single
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
ENV" || return 1

    run_ssh_expect_success "$scenario" "Prepare deploy path permissions" "$host" "sudo mkdir -p /var/www/staging && sudo chown deploy:www-data /var/www && sudo chmod 2775 /var/www && sudo chown -R deploy:www-data /var/www/staging && sudo chmod 2775 /var/www/staging" || return 1

    run_artisan_expect_success "$scenario" "Release #1" "deployer:release staging --no-confirm --skip-health-check" || return 1

    assert_ssh "$host" "test -L /var/www/staging/current" 0 "$SSH_PORT" "current symlink exists"
    assert_ssh "$host" "test -d /var/www/staging/releases" 0 "$SSH_PORT" "releases directory exists"
    assert_ssh "$host" "test -d /var/www/staging/shared" 0 "$SSH_PORT" "shared directory exists"
    assert_ssh "$host" "test -d /var/www/staging/.dep" 0 "$SSH_PORT" "metadata directory exists"

    if ! wait_for_remote_http "$host" "/up" 60 200; then
        fail "Health endpoint became ready" "Timeout waiting for /up"
        return 1
    fi
    assert_remote_http "$host" "/up" "200" "Health endpoint works after release"

    run_artisan_expect_success "$scenario" "Release #2" "deployer:release staging --no-confirm --skip-health-check" || return 1

    local release_count
    release_count=$(ssh_cmd "$host" "ls -1 /var/www/staging/releases/ | wc -l" "$SSH_PORT" | tr -d '[:space:]')
    release_count="${release_count:-0}"
    assert_equal "$release_count" "2" "Two releases exist after second deploy"

    run_artisan_expect_success "$scenario" "Rollback" "deployer:rollback staging --no-confirm" || return 1

    local current_target
    current_target=$(ssh_cmd "$host" "readlink /var/www/staging/current" "$SSH_PORT")
    assert_contains "$current_target" "releases/" "Current symlink points to releases path"

    local diagnose_output
    diagnose_output="$(run_artisan_capture "$scenario" "Diagnose fresh server" "deployer:server diagnose staging --full" || true)"
    assert_contains "$diagnose_output" "Overall:" "Diagnose command produced summary"

    run_artisan_expect_success "$scenario" "Database backup" "deployer:db backup staging" || return 1
    run_artisan_expect_success "$scenario" "Sync deployment" "deployer:sync staging --no-confirm" || return 1
}

scenario_existing() {
    local scenario="existing"
    local host="$1"

    section "SCENARIO: Existing Flat Laravel Migration (OrbStack VM)"

    write_env_staging "$host" "$SSH_PORT"

    if ! prepare_existing_vm_fixture "$scenario" "$host"; then
        return 1
    fi

    assert_ssh "$host" "echo ok" 0 "$SSH_PORT" "SSH connectivity"
    assert_ssh "$host" "test -f /var/www/staging/public/index.php" 0 "$SSH_PORT" "Flat app exists"
    assert_ssh "$host" "test ! -d /var/www/staging/releases" 0 "$SSH_PORT" "No releases directory yet"

    if ! wait_for_remote_http "$host" "/up" 60 200; then
        fail "Existing app pre-migration readiness" "Timeout waiting for /up"
        return 1
    fi
    assert_remote_http "$host" "/up" "200" "Pre-migration app health"

    run_artisan_expect_success "$scenario" "Initialize existing server" "deployer:setup init staging --force --skip-db-backup --skip-project-backup" || return 1

    assert_ssh "$host" "test -d /var/www/staging/releases" 0 "$SSH_PORT" "releases directory created"
    assert_ssh "$host" "test -L /var/www/staging/current" 0 "$SSH_PORT" "current symlink created"
    assert_ssh "$host" "test -d /var/www/staging/shared" 0 "$SSH_PORT" "shared directory created"
    assert_ssh "$host" "test -L /var/www/staging/current/storage" 0 "$SSH_PORT" "storage symlink created"
    assert_ssh "$host" "test -f /var/www/staging/shared/.env" 0 "$SSH_PORT" "shared env exists"

    run_ssh_expect_success "$scenario" "Switch Nginx root to current/public" "$host" "sudo sed -i 's|root /var/www/staging/public|root /var/www/staging/current/public|' /etc/nginx/sites-available/staging && sudo nginx -t && sudo systemctl reload nginx" || return 1

    if ! wait_for_remote_http "$host" "/up" 60 200; then
        fail "Existing app post-migration readiness" "Timeout waiting for /up"
        return 1
    fi
    assert_remote_http "$host" "/up" "200" "Post-migration app health"

    run_artisan_expect_success "$scenario" "Release after migration" "deployer:release staging --no-confirm --skip-health-check" || return 1

    local release_count
    release_count=$(ssh_cmd "$host" "ls -1 /var/www/staging/releases/ | wc -l" "$SSH_PORT" | tr -d '[:space:]')
    release_count="${release_count:-0}"
    assert_gte "$release_count" 2 "At least two releases exist (migration + deploy)"

    run_artisan_expect_success "$scenario" "Rollback after migration" "deployer:rollback staging --no-confirm" || return 1
    assert_ssh "$host" "test -L /var/www/staging/current" 0 "$SSH_PORT" "current symlink remains after rollback"
}

scenario_drifted() {
    local scenario="drifted"
    local host="$1"

    section "SCENARIO: Drifted Existing Server Recovery (OrbStack VM)"

    write_env_staging "$host" "$SSH_PORT"

    if ! prepare_existing_vm_fixture "$scenario" "$host"; then
        return 1
    fi

    assert_ssh "$host" "echo ok" 0 "$SSH_PORT" "SSH connectivity"

    run_artisan_expect_success "$scenario" "Initialize existing server" "deployer:setup init staging --force --skip-db-backup --skip-project-backup" || return 1
    run_artisan_expect_success "$scenario" "Baseline release before drift" "deployer:release staging --no-confirm --skip-health-check" || return 1

    run_ssh_expect_success "$scenario" "Ensure Nginx points to current/public" "$host" "sudo sed -i 's|root /var/www/staging/public|root /var/www/staging/current/public|' /etc/nginx/sites-available/staging && sudo systemctl reload nginx" || return 1

    apply_drift_state "$scenario" "$host" || return 1

    assert_ssh "$host" "stat -c '%U:%G' /var/www/staging/current/storage | grep -q 'root:root'" 0 "$SSH_PORT" "Drift introduced: root-owned storage"
    assert_ssh "$host" "grep -q 'root /var/www/staging/public;' /etc/nginx/sites-available/staging" 0 "$SSH_PORT" "Drift introduced: stale Nginx root"

    run_artisan_expect_failure "$scenario" "Diagnose detects drift" "deployer:server diagnose staging --full" || return 1
    run_artisan_expect_failure "$scenario" "Diagnose --fix generates remediation script" "deployer:server diagnose staging --full --fix" || return 1

    apply_generated_fix_script "$scenario" "$host" || return 1

    run_ssh_expect_success "$scenario" "Repair Nginx root after drift" "$host" "sudo sed -i 's|root /var/www/staging/public|root /var/www/staging/current/public|' /etc/nginx/sites-available/staging && sudo nginx -t && sudo systemctl reload nginx" || return 1

    run_artisan_expect_success "$scenario" "Release after drift remediation" "deployer:release staging --no-confirm --skip-health-check" || return 1

    assert_ssh "$host" "find /var/www/staging/current -user root -type f 2>/dev/null | wc -l | tr -d '[:space:]' | grep -q '^0$'" 0 "$SSH_PORT" "No root-owned files remain in current"
    assert_ssh "$host" "find /var/www/staging/current/storage ! -perm -g+w 2>/dev/null | wc -l | tr -d '[:space:]' | grep -q '^0$'" 0 "$SSH_PORT" "Storage tree is group writable"

    if ! wait_for_remote_http "$host" "/up" 60 200; then
        fail "Existing app post-drift readiness" "Timeout waiting for /up"
        return 1
    fi
    assert_remote_http "$host" "/up" "200" "Health endpoint recovers after drift remediation"

    local diagnose_after
    diagnose_after="$(run_artisan_capture "$scenario" "Diagnose after remediation" "deployer:server diagnose staging --full" || true)"
    assert_contains "$diagnose_after" "Overall:" "Diagnose output captured after remediation"
}

run_consuming_project_checks() {
    if [ "${#CONSUMING_PROJECTS[@]}" -eq 0 ]; then
        if [ "$CONSUMING_ONLY" -eq 1 ]; then
            fail "Consuming project checks" "No projects provided. Use --consuming-project or --consuming-projects-file."
            OVERALL_EXIT=1
            return 1
        fi

        return 0
    fi

    section "CONSUMING PROJECT VALIDATION"

    local project
    local project_status
    local project_slug
    local project_log

    for project in "${CONSUMING_PROJECTS[@]}"; do
        project_slug="$(slugify "$project")"
        project_log="$RUN_ARTIFACT_DIR/consuming-projects/${project_slug}.log"
        project_status="passed"

        step "Validating consuming project: $project"

        if [ ! -d "$project" ] || [ ! -f "$project/artisan" ]; then
            fail "Consuming project exists ($project)" "Missing Laravel project/artisan"
            project_status="failed"
        else
            if ! validate_policy_file "$project/.deploy/deploy.json" "Policy check: $project"; then
                project_status="failed"
            fi

            if [ "$project_status" = "passed" ]; then
                if (
                    cd "$project"
                    composer config --unset repositories.deployer-local || true
                    composer config repositories.deployer-local "{\"type\":\"path\",\"url\":\"$PACKAGE_DIR\",\"options\":{\"symlink\":false}}"
                    if composer show shaf/laravel-deployer >/dev/null 2>&1; then
                        composer update shaf/laravel-deployer --no-interaction --prefer-dist
                    else
                        composer require shaf/laravel-deployer:@dev --no-interaction --prefer-dist
                    fi
                    php artisan deployer:release staging --dry-run --no-confirm
                ) > "$project_log" 2>&1; then
                    pass "Consuming project validation: $project"
                else
                    fail "Consuming project validation: $project" "See log: $project_log"
                    tail -n 30 "$project_log" || true
                    project_status="failed"
                fi
            fi
        fi

        CONSUMER_RESULTS+=("{\"path\":\"$project\",\"status\":\"$project_status\"}")

        if [ "$project_status" = "failed" ]; then
            OVERALL_EXIT=1
        fi
    done
}

run_scenario() {
    local scenario="$1"
    local scenario_start_epoch
    local scenario_end_epoch
    local pass_before
    local fail_before
    local pass_after
    local fail_after
    local scenario_exit=0
    local vm_name=""
    local host=""

    mkdir -p "$RUN_ARTIFACT_DIR/scenarios/$scenario"

    pass_before=$PASS_COUNT
    fail_before=$FAIL_COUNT
    scenario_start_epoch=$(date +%s)

    if ! setup_vm_for_scenario "$scenario"; then
        scenario_exit=1
    else
        vm_name="$CURRENT_SCENARIO_VM_NAME"
        host="$CURRENT_SCENARIO_HOST"
    fi

    if [ "$scenario_exit" -eq 0 ]; then
        set +e
        case "$scenario" in
            fresh) scenario_fresh "$host" ;;
            existing) scenario_existing "$host" ;;
            drifted) scenario_drifted "$host" ;;
            *)
                echo "Unsupported scenario: $scenario"
                scenario_exit=1
                ;;
        esac
        scenario_exit=$?
        set -e
    fi

    if [ -n "$vm_name" ] && [ -n "$host" ]; then
        collect_vm_artifacts "$scenario" "$vm_name" "$host"
    fi

    pass_after=$PASS_COUNT
    fail_after=$FAIL_COUNT
    scenario_end_epoch=$(date +%s)

    local passed_delta=$((pass_after - pass_before))
    local failed_delta=$((fail_after - fail_before))
    local duration_seconds=$((scenario_end_epoch - scenario_start_epoch))
    local status="passed"

    if [ "$scenario_exit" -ne 0 ] || [ "$failed_delta" -gt 0 ]; then
        status="failed"
        OVERALL_EXIT=1
    fi

    if [ "$status" = "passed" ]; then
        pass "Scenario '$scenario' completed"
    else
        fail "Scenario '$scenario' failed" "See artifacts in $RUN_ARTIFACT_DIR/scenarios/$scenario"
    fi

    SCENARIO_RESULTS+=("{\"name\":\"$scenario\",\"status\":\"$status\",\"duration_seconds\":$duration_seconds,\"passed\":$passed_delta,\"failed\":$failed_delta,\"exit_code\":$scenario_exit}")
}

write_results_json() {
    local run_finished_at
    local run_end_epoch
    local duration_seconds
    local i
    local total

    run_finished_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    run_end_epoch=$(date -u +%s)
    duration_seconds=$((run_end_epoch - RUN_STARTED_EPOCH))

    total=$((PASS_COUNT + FAIL_COUNT))

    {
        echo "{"
        echo "  \"run_id\": \"$RUN_ID\"," 
        echo "  \"started_at\": \"$RUN_STARTED_AT_UTC\"," 
        echo "  \"finished_at\": \"$run_finished_at\"," 
        echo "  \"duration_seconds\": $duration_seconds,"
        if [ "$OVERALL_EXIT" -eq 0 ] && [ "$FAIL_COUNT" -eq 0 ]; then
            echo "  \"status\": \"passed\"," 
        else
            echo "  \"status\": \"failed\"," 
        fi
        echo "  \"summary\": {"
        echo "    \"passed\": $PASS_COUNT,"
        echo "    \"failed\": $FAIL_COUNT,"
        echo "    \"total\": $total"
        echo "  },"

        echo "  \"scenarios\": ["
        for i in "${!SCENARIO_RESULTS[@]}"; do
            if [ "$i" -gt 0 ]; then
                echo "    ,${SCENARIO_RESULTS[$i]}"
            else
                echo "    ${SCENARIO_RESULTS[$i]}"
            fi
        done
        echo "  ],"

        echo "  \"consuming_projects\": ["
        for i in "${!CONSUMER_RESULTS[@]}"; do
            if [ "$i" -gt 0 ]; then
                echo "    ,${CONSUMER_RESULTS[$i]}"
            else
                echo "    ${CONSUMER_RESULTS[$i]}"
            fi
        done
        echo "  ]"
        echo "}"
    } > "$RESULTS_JSON"
}

main() {
    parse_args "$@"
    resolve_scenarios
    prepare_artifacts

    require_command orbctl
    require_command composer
    require_command php
    require_command npm
    require_command ssh
    require_command scp
    require_command rsync

    validate_policy_file "$FIXTURES_DIR/deploy/deploy.json" "Fixture policy: source deploy.json" || exit 1

    print_run_header

    generate_ssh_keys
    create_test_app

    if [ "$CONSUMING_ONLY" -eq 0 ]; then
        for scenario in "${SELECTED_SCENARIOS[@]}"; do
            run_scenario "$scenario"
        done
    fi

    run_consuming_project_checks || true

    write_results_json

    summary || true
    echo "Results JSON: $RESULTS_JSON"

    if [ "$OVERALL_EXIT" -ne 0 ] || [ "$FAIL_COUNT" -ne 0 ]; then
        exit 1
    fi
}

main "$@"
