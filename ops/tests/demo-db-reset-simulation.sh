#!/usr/bin/env bash
set -Eeuo pipefail
trap 'status=$?; echo "Demo reset simulation failed at line ${BASH_LINENO[0]} (status $status)." >&2; exit "$status"' ERR

backend_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
sim_dir="$(mktemp -d "$backend_dir/.demo-reset-sim.XXXXXX")"
case "$sim_dir" in
    "$backend_dir"/.demo-reset-sim.*) ;;
    *) echo "Simulation temp path escaped the backend workspace." >&2; exit 1 ;;
esac
trap 'rm -rf -- "$sim_dir"' EXIT

bin_dir="$sim_dir/bin"
mkdir -p "$bin_dir"
export GH_CALL_LOG="$sim_dir/gh-calls"
export FAKE_LOG_PATH="$sim_dir/remote-log"
sha=0123456789abcdef0123456789abcdef01234567
export GITHUB_REPOSITORY=example/GAM
export GITHUB_SHA="$sha"
export GITHUB_REF=refs/heads/main
export GITHUB_EVENT_NAME=push

cat > "$bin_dir/gh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'api\n' >> "$GH_CALL_LOG"
case "$FAKE_PR_CASE" in
    merged)
        printf '[{"base":{"ref":"main"},"merged_at":"2026-01-01T00:00:00Z","merge_commit_sha":"%s"}]\n' "$GITHUB_SHA"
        ;;
    unmerged)
        printf '[{"base":{"ref":"main"},"merged_at":null,"merge_commit_sha":"%s"}]\n' "$GITHUB_SHA"
        ;;
    direct-push)
        printf '[]\n'
        ;;
    other-base)
        printf '[{"base":{"ref":"develop"},"merged_at":"2026-01-01T00:00:00Z","merge_commit_sha":"%s"}]\n' "$GITHUB_SHA"
        ;;
    other-sha)
        printf '[{"base":{"ref":"main"},"merged_at":"2026-01-01T00:00:00Z","merge_commit_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}]\n'
        ;;
    *) printf '[]\n' ;;
esac
SH

cat > "$bin_dir/docker" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
command=$1
shift
if [[ "$command" == ps ]]; then
    service=
    for arg in "$@"; do
        [[ "$arg" == label=com.docker.compose.service=* ]] && service=${arg##*=}
    done
    case "$service" in
        api) printf 'api-id\n' ;;
        postgres) printf 'postgres-id\n' ;;
        gateway) printf 'gateway-id\n' ;;
        *) exit 1 ;;
    esac
    exit 0
fi
if [[ "$command" == inspect ]]; then
    template=$2
    container=$3
    case "$template:$container" in
        *Config.Image*:api-id) printf '%s\n' "${FAKE_API_IMAGE:-gam-backend:$FAKE_RELEASE_SHA}" ;;
        *State.Health.Status*:*) printf 'healthy\n' ;;
        *) exit 1 ;;
    esac
    exit 0
fi
if [[ "$command" != exec ]]; then
    exit 1
fi

if [[ "$1" == -i ]]; then
    shift
    container=$1
    shift
    if [[ "$1" == pg_restore ]]; then
        cat >/dev/null
        if [[ "${2:-}" == --list ]]; then
            printf 'backup-verify-list\n' >> "$FAKE_LOG_PATH"
            verify_step=list
        else
            printf 'backup-verify-parse\n' >> "$FAKE_LOG_PATH"
            verify_step=parse
        fi
        [[ "${FAKE_RESTORE_FAIL:-false}" != true && "${FAKE_RESTORE_FAIL:-false}" != "$verify_step" ]]
        exit $?
    fi
fi

container=$1
shift
case "$container:$1" in
    postgres-id:sh)
        script=$3
        if [[ "$script" == *pg_dump* ]]; then
            printf 'backup-dump\n' >> "$FAKE_LOG_PATH"
            [[ "${FAKE_DUMP_FAIL:-false}" != true ]] || exit 1
            printf 'fake-custom-database-archive'
        elif [[ "$script" == *current_database* ]]; then
            printf 'database-guard\n' >> "$FAKE_LOG_PATH"
            [[ "${FAKE_DB_GUARD_FAIL:-false}" != true ]] || exit 1
            [[ "${FAKE_POSTGRES_DB:-gam_demo}" == "$5" && "${FAKE_CURRENT_DATABASE:-gam_demo}" == "$5" ]] || exit 1
        else
            exit 1
        fi
        ;;
    api-id:php)
        shift
        if [[ "$1" == artisan ]]; then
            printf 'reset\n' >> "$FAKE_LOG_PATH"
            [[ "${FAKE_MIGRATE_FAIL:-false}" != true ]] || exit 1
        elif [[ "$1" == -r ]]; then
            php_code=$2
            if [[ "$php_code" == *'config("app.name")'* ]]; then
                printf '%s\n' \
                    "${FAKE_APP_NAME:-GAM}" \
                    "${FAKE_APP_ENV:-production}" \
                    "${FAKE_DB_CONNECTION:-pgsql}" \
                    "${FAKE_DB_HOST:-postgres}" \
                    "${FAKE_LIVE_DB_DATABASE:-gam_demo}" \
                    "${FAKE_ENV_DEMO_VPS_HOST:-demo.example}" \
                    "${FAKE_ENV_DEMO_DB_DATABASE:-gam_demo}" \
                    "${FAKE_CONFIG_RESET_ENABLED:-true}" \
                    "${FAKE_RAW_RESET_ENABLED:-true}" \
                    "${FAKE_ADMIN_PASSWORD_SET:-true}"
            else
                printf 'seed-verify\n' >> "$FAKE_LOG_PATH"
                printf 'seed-ok\n'
            fi
        else
            exit 1
        fi
        ;;
    gateway-id:wget)
        printf 'public-health\n' >> "$FAKE_LOG_PATH"
        ;;
    *) exit 1 ;;
esac
SH
chmod +x "$bin_dir/gh" "$bin_dir/docker"

cat > "$sim_dir/deploy.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'deploy\n' >> "$FAKE_LOG_PATH"
[[ "${FAKE_DEPLOY_FAIL:-false}" != true ]]
SH
chmod +x "$sim_dir/deploy.sh"

export PATH="$bin_dir:$PATH"
request_script="$backend_dir/ops/request-demo-db-reset.sh"
deploy_helper="$backend_dir/ops/deploy-backend-with-demo-reset.sh"

assert_request() {
    local expected=$1
    local actual
    actual="$(bash "$request_script")"
    [[ "$actual" == "$expected" ]] || {
        echo "Expected event reset request '$expected', received '$actual'." >&2
        exit 1
    }
}

export DEMO_DB_RESET_ENABLED=true FAKE_PR_CASE=merged
assert_request true
export GITHUB_EVENT_NAME=pull_request
assert_request false
export GITHUB_EVENT_NAME=pull_request_target
assert_request false
export GITHUB_EVENT_NAME=workflow_dispatch
assert_request false
export GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/develop
assert_request false
export GITHUB_REF=refs/heads/main FAKE_PR_CASE=direct-push
assert_request false
export FAKE_PR_CASE=unmerged
assert_request false
export FAKE_PR_CASE=other-base
assert_request false
export FAKE_PR_CASE=other-sha
assert_request false
export FAKE_PR_CASE=merged DEMO_DB_RESET_ENABLED=false
api_calls_before=$(wc -l < "$GH_CALL_LOG")
assert_request false
[[ "$(wc -l < "$GH_CALL_LOG")" -eq "$api_calls_before" ]]
export DEMO_DB_RESET_ENABLED=True
assert_request false
export DEMO_DB_RESET_ENABLED=true

run_helper() {
    local home_dir="$sim_dir/home-$RANDOM-$RANDOM"
    mkdir -p "$home_dir"
    HOME="$home_dir" FAKE_RELEASE_SHA="$sha" \
        bash "$deploy_helper" "$sim_dir/deploy.sh" "v0.1.1" "$sha" "$1" "$2" "$3" "$4" \
        >/dev/null 2>&1
}

run_helper_in_home() {
    local home_dir=$1
    shift
    HOME="$home_dir" FAKE_RELEASE_SHA="$sha" \
        bash "$deploy_helper" "$sim_dir/deploy.sh" "v0.1.1" "$sha" "$@" \
        >/dev/null 2>&1
}

assert_no_reset() {
    if grep -Fxq reset "$FAKE_LOG_PATH"; then
        echo "A guarded simulation unexpectedly ran the destructive reset." >&2
        exit 1
    fi
}

: > "$FAKE_LOG_PATH"
export FAKE_APP_NAME=GAM FAKE_APP_ENV=production FAKE_DB_CONNECTION=pgsql FAKE_DB_HOST=postgres
export FAKE_LIVE_DB_DATABASE=gam_demo FAKE_ENV_DEMO_VPS_HOST=demo.example FAKE_ENV_DEMO_DB_DATABASE=gam_demo
export FAKE_CONFIG_RESET_ENABLED=true FAKE_RAW_RESET_ENABLED=true FAKE_ADMIN_PASSWORD_SET=true FAKE_POSTGRES_DB=gam_demo
run_helper true demo.example demo.example gam_demo
grep -Fxq deploy "$FAKE_LOG_PATH"
grep -Fxq backup-dump "$FAKE_LOG_PATH"
grep -Fxq backup-verify-list "$FAKE_LOG_PATH"
grep -Fxq backup-verify-parse "$FAKE_LOG_PATH"
grep -Fxq reset "$FAKE_LOG_PATH"
grep -Fxq seed-verify "$FAKE_LOG_PATH"
grep -Fxq public-health "$FAKE_LOG_PATH"
deploy_line=$(grep -nFx deploy "$FAKE_LOG_PATH" | cut -d: -f1)
dump_line=$(grep -nFx backup-dump "$FAKE_LOG_PATH" | cut -d: -f1)
verify_line=$(grep -nFx backup-verify-parse "$FAKE_LOG_PATH" | cut -d: -f1)
reset_line=$(grep -nFx reset "$FAKE_LOG_PATH" | cut -d: -f1)
seed_line=$(grep -nFx seed-verify "$FAKE_LOG_PATH" | cut -d: -f1)
health_line=$(grep -nFx public-health "$FAKE_LOG_PATH" | cut -d: -f1)
(( deploy_line < dump_line && dump_line < verify_line && verify_line < reset_line && reset_line < seed_line && seed_line < health_line ))

# An exact-SHA workflow rerun must not reset the same merge twice.
: > "$FAKE_LOG_PATH"
success_home="$sim_dir/home-success"
mkdir -p "$success_home"
run_helper_in_home "$success_home" true demo.example demo.example gam_demo
grep -Fxq deploy "$FAKE_LOG_PATH"
! grep -Fxq backup-dump "$FAKE_LOG_PATH"
! grep -Fxq reset "$FAKE_LOG_PATH"
grep -Fxq public-health "$FAKE_LOG_PATH"

# A direct main push deploys normally and does not enter the backup/reset path.
: > "$FAKE_LOG_PATH"
run_helper false demo.example demo.example gam_demo
grep -Fxq deploy "$FAKE_LOG_PATH"
grep -Fxq public-health "$FAKE_LOG_PATH"
! grep -Fxq backup-dump "$FAKE_LOG_PATH"
assert_no_reset

: > "$FAKE_LOG_PATH"
export FAKE_DEPLOY_FAIL=true
if run_helper true demo.example demo.example gam_demo; then
    echo "Deployment failure was not propagated." >&2
    exit 1
fi
unset FAKE_DEPLOY_FAIL
assert_no_reset
! grep -Fxq backup-dump "$FAKE_LOG_PATH"

for guard in host database api-image app-name app-env connection db-host live-db target-env toggle admin-password postgres-db postgres-current-db; do
    : > "$FAKE_LOG_PATH"
    export FAKE_APP_NAME=GAM FAKE_APP_ENV=production FAKE_DB_CONNECTION=pgsql FAKE_DB_HOST=postgres
    export FAKE_LIVE_DB_DATABASE=gam_demo FAKE_ENV_DEMO_VPS_HOST=demo.example FAKE_ENV_DEMO_DB_DATABASE=gam_demo
    export FAKE_CONFIG_RESET_ENABLED=true FAKE_RAW_RESET_ENABLED=true FAKE_ADMIN_PASSWORD_SET=true FAKE_POSTGRES_DB=gam_demo
    unset FAKE_API_IMAGE FAKE_CURRENT_DATABASE
    target_host=demo.example
    expected_host=demo.example
    database=gam_demo
    case "$guard" in
        host) expected_host=other.example ;;
        database) database=GAM_TESTING ;;
        api-image) export FAKE_API_IMAGE=gam-backend:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ;;
        app-name) export FAKE_APP_NAME=OTHER ;;
        app-env) export FAKE_APP_ENV=staging ;;
        connection) export FAKE_DB_CONNECTION=mysql ;;
        db-host) export FAKE_DB_HOST=elsewhere ;;
        live-db) export FAKE_LIVE_DB_DATABASE=other_db ;;
        target-env) export FAKE_ENV_DEMO_VPS_HOST=other.example ;;
        toggle) export FAKE_RAW_RESET_ENABLED=TRUE ;;
        admin-password) export FAKE_ADMIN_PASSWORD_SET=false ;;
        postgres-db) export FAKE_POSTGRES_DB=other_db ;;
        postgres-current-db) export FAKE_CURRENT_DATABASE=other_db ;;
    esac
    if run_helper true "$target_host" "$expected_host" "$database"; then
        echo "The $guard guard did not abort the reset simulation." >&2
        exit 1
    fi
    assert_no_reset
    ! grep -Fxq backup-dump "$FAKE_LOG_PATH"
done

: > "$FAKE_LOG_PATH"
export FAKE_APP_NAME=GAM FAKE_APP_ENV=production FAKE_DB_CONNECTION=pgsql FAKE_DB_HOST=postgres
export FAKE_LIVE_DB_DATABASE=gam_demo FAKE_ENV_DEMO_VPS_HOST=demo.example FAKE_ENV_DEMO_DB_DATABASE=gam_demo
export FAKE_CONFIG_RESET_ENABLED=true FAKE_RAW_RESET_ENABLED=true FAKE_ADMIN_PASSWORD_SET=true FAKE_POSTGRES_DB=gam_demo
unset FAKE_API_IMAGE FAKE_CURRENT_DATABASE
export FAKE_RESTORE_FAIL=parse
if run_helper true demo.example demo.example gam_demo; then
    echo "Backup verification failure did not abort the reset simulation." >&2
    exit 1
fi
assert_no_reset
grep -Fxq backup-dump "$FAKE_LOG_PATH"
grep -Fxq backup-verify-list "$FAKE_LOG_PATH"
grep -Fxq backup-verify-parse "$FAKE_LOG_PATH"
unset FAKE_RESTORE_FAIL

: > "$FAKE_LOG_PATH"
export FAKE_DUMP_FAIL=true
if run_helper true demo.example demo.example gam_demo; then
    echo "Backup creation failure did not abort the reset simulation." >&2
    exit 1
fi
assert_no_reset
unset FAKE_DUMP_FAIL

: > "$FAKE_LOG_PATH"
export FAKE_MIGRATE_FAIL=true
interrupted_home="$sim_dir/home-interrupted"
mkdir -p "$interrupted_home"
if run_helper_in_home "$interrupted_home" true demo.example demo.example gam_demo; then
    echo "Reset command failure was not propagated." >&2
    exit 1
fi
grep -Fxq reset "$FAKE_LOG_PATH"
! grep -Fxq seed-verify "$FAKE_LOG_PATH"
unset FAKE_MIGRATE_FAIL

# A workflow rerun after an interrupted destructive attempt fails closed without repeating it.
: > "$FAKE_LOG_PATH"
if run_helper_in_home "$interrupted_home" true demo.example demo.example gam_demo; then
    echo "An interrupted reset was automatically repeated." >&2
    exit 1
fi
assert_no_reset
! grep -Fxq backup-dump "$FAKE_LOG_PATH"

echo "Demo reset event, opt-in, deployment, guard, backup ordering, idempotency, and failure simulations passed."
