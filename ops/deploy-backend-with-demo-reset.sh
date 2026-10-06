#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -ne 7 ]]; then
    echo "Expected deploy script, tag, SHA, reset request, target host, expected demo host, and expected database." >&2
    exit 2
fi

deploy_script=$1
release_tag=$2
release_sha=$3
reset_requested=$4
ssh_target_host=$5
expected_demo_host=$6
expected_demo_database=$7

fail() {
    echo "$1" >&2
    exit 1
}

[[ "$release_sha" =~ ^[0-9a-f]{40}$ ]] || fail "Invalid release SHA."
[[ "$reset_requested" == true || "$reset_requested" == false ]] || fail "Invalid reset request flag."

# ponytail: the existing deploy script stays the deployment authority; this wrapper only adds a verified post-deploy boundary.
bash "$deploy_script" "$release_tag" "$release_sha"

container_for_service() {
    local service=$1
    local ids

    ids="$(docker ps \
        --filter 'label=com.docker.compose.project=gam-prod' \
        --filter "label=com.docker.compose.service=$service" \
        --format '{{.ID}}')"
    [[ -n "$ids" && "$ids" != *$'\n'* ]] || fail "Expected exactly one running gam-prod $service container."
    printf '%s' "$ids"
}

wait_healthy() {
    local container=$1
    local service=$2
    local status

    for _ in {1..60}; do
        status="$(docker inspect --format '{{.State.Health.Status}}' "$container")"
        [[ "$status" == healthy ]] && return 0
        sleep 5
    done

    fail "The gam-prod $service container did not become healthy."
}

api_container="$(container_for_service api)"
postgres_container="$(container_for_service postgres)"
gateway_container="$(container_for_service gateway)"
api_image="$(docker inspect --format '{{.Config.Image}}' "$api_container")"
[[ "$api_image" == "gam-backend:$release_sha" ]] || fail "The running GAM API image does not match the merged release SHA."
wait_healthy "$api_container" api
wait_healthy "$postgres_container" postgres

verify_public_health() {
    local current_gateway

    current_gateway="$(container_for_service gateway)"
    wait_healthy "$current_gateway" gateway
    docker exec "$current_gateway" wget -qO- http://127.0.0.1:8080/status >/dev/null \
        || fail "The GAM status endpoint did not pass its health check."
}

if [[ "$reset_requested" != true ]]; then
    verify_public_health
    echo "GAM deployment health checks passed; demo database reset was not requested."
    exit 0
fi

[[ -n "$expected_demo_host" && "$ssh_target_host" == "$expected_demo_host" ]] \
    || fail "The SSH target does not match DEMO_VPS_HOST."
[[ -n "$expected_demo_database" ]] || fail "DEMO_DB_DATABASE is not configured."
[[ "$expected_demo_database" =~ ^[A-Za-z0-9_]+$ ]] || fail "DEMO_DB_DATABASE has an invalid name."
expected_demo_database_lower="${expected_demo_database,,}"
[[ "$expected_demo_database_lower" != gam_testing && "$expected_demo_database_lower" != *_testing ]] \
    || fail "Refusing to reset a testing database."

# Print only allowlisted, non-secret effective values from Laravel's bootstrapped config.
live_config_output="$(docker exec "$api_container" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo implode("\n", [
    (string) config("app.name"),
    (string) config("app.env"),
    (string) config("database.default"),
    (string) config("database.connections.pgsql.host"),
    (string) config("database.connections.pgsql.database"),
    (string) (getenv("DEMO_VPS_HOST") ?: ""),
    (string) (getenv("DEMO_DB_DATABASE") ?: ""),
    config("app.demo_db_reset_enabled") === true ? "true" : "false",
    getenv("DEMO_DB_RESET_ENABLED") === "true" ? "true" : "false",
    is_string(config("auth.admin.password")) && trim(config("auth.admin.password")) !== "" ? "true" : "false",
]);')"
mapfile -t live_config <<< "$live_config_output"
[[ ${#live_config[@]} -eq 10 ]] || fail "Could not verify effective GAM demo reset configuration."
[[ "${live_config[0]}" == GAM ]] || fail "The API container is not configured as GAM."
[[ "${live_config[1]}" == production ]] || fail "The API container is not running in production."
[[ "${live_config[2]}" == pgsql && "${live_config[3]}" == postgres ]] \
    || fail "The API container is not connected to the expected PostgreSQL service."
[[ "${live_config[4]}" == "$expected_demo_database" ]] \
    || fail "The API database does not match DEMO_DB_DATABASE."
[[ "${live_config[5]}" == "$expected_demo_host" && "${live_config[6]}" == "$expected_demo_database" ]] \
    || fail "The API .env.production demo target does not match the GitHub production target."
[[ "${live_config[7]}" == true && "${live_config[8]}" == true ]] \
    || fail "DEMO_DB_RESET_ENABLED must be exactly true in the API .env.production."
[[ "${live_config[9]}" == true ]] || fail "ADMIN_PASSWORD must be configured in the API .env.production."

docker exec "$postgres_container" sh -ec '
    test "$POSTGRES_DB" = "$1"
    test -n "$POSTGRES_USER"
    PGPASSWORD="$POSTGRES_PASSWORD" psql -h 127.0.0.1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atqc "SELECT current_database()" \
        | grep -Fx "$1" >/dev/null
' sh "$expected_demo_database" || fail "The gam-prod PostgreSQL service does not report the expected demo database."

completion_dir="$HOME/.cache/gam-demo-reset-complete"
completion_marker="$completion_dir/$release_sha"
in_progress_marker="$completion_dir/$release_sha.in-progress"
if [[ -f "$completion_marker" ]]; then
    echo "A demo database reset is already recorded for this merge SHA; skipping the duplicate reset."
    verify_public_health
    exit 0
fi
[[ ! -e "$in_progress_marker" ]] \
    || fail "A demo reset for this merge SHA was interrupted; refusing to repeat it automatically."

backup_dir="$HOME/gam-demo-db-backups"
umask 077
mkdir -p "$backup_dir" "$completion_dir"
chmod 700 "$backup_dir" "$completion_dir"
backup_file="$(mktemp "$backup_dir/gam-demo-${release_sha}-XXXXXX")"

docker exec "$postgres_container" sh -ec '
    PGPASSWORD="$POSTGRES_PASSWORD" pg_dump --format=custom --no-owner --no-acl \
        --host=127.0.0.1 --username="$POSTGRES_USER" --dbname="$POSTGRES_DB"
' > "$backup_file" || fail "The pre-reset demo database backup failed."
[[ -s "$backup_file" ]] || fail "The pre-reset demo database backup is empty."
docker exec -i "$postgres_container" pg_restore --list < "$backup_file" >/dev/null \
    || fail "The pre-reset demo database backup could not be verified."
docker exec -i "$postgres_container" pg_restore --exit-on-error --file=/dev/null < "$backup_file" >/dev/null 2>&1 \
    || fail "The pre-reset demo database backup could not be parsed."
echo "Verified pre-reset database backup: $backup_file"

# Acción destructiva: tras las guardas y el respaldo verificado, reinicia y vuelve a sembrar la demo.
# Mark the destructive boundary atomically so a failed/aborted reset cannot be repeated by a workflow rerun.
(set -o noclobber; : > "$in_progress_marker") 2>/dev/null \
    || fail "A demo reset for this merge SHA was already started; refusing to repeat it automatically."
docker exec "$api_container" php artisan migrate:fresh --seed --force --no-interaction

docker exec "$api_container" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin = \App\Models\User::query()->where("email", config("auth.admin.email"))->first();
$usable = $admin && ! $admin->trashed() && $admin->hasRole("admin") && $admin->can("admin.dashboard.view")
    && \App\Models\Lots\Flock::query()->where("code", "DEMO-LOT-A")->exists();
if (! $usable) {
    fwrite(STDERR, "The seeded demo admin or sample data is unavailable.\n");
    exit(1);
}
echo "seed-ok\n";
' | grep -Fx 'seed-ok' >/dev/null || fail "The reset database is missing its administrative account or demo records."

verify_public_health
touch "$completion_marker"
rm -f -- "$in_progress_marker"
echo "Demo database reset and seed completed for merge SHA $release_sha."
