#!/bin/sh

set -eu

psql -v ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" \
    --dbname postgres \
    -v test_database="${POSTGRES_DB}_testing" \
    -v test_owner="$POSTGRES_USER" <<'SQL'
SELECT format('CREATE DATABASE %I OWNER %I', :'test_database', :'test_owner')
WHERE NOT EXISTS (
    SELECT FROM pg_database WHERE datname = :'test_database'
)
\gexec
SQL
