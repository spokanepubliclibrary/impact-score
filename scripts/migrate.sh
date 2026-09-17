#!/usr/bin/env bash
# scripts/migrate.sh — host-side migration runner
#
# Called by `make db-migrate`. Applies any pending db/migrations/*.sql files
# against the running db container via docker exec. Safe to run repeatedly;
# already-applied files are skipped.
#
# Usage:
#   make db-migrate
#   bash scripts/migrate.sh
#
# Requires .env to be present at the project root (DB_USER, DB_PASSWORD, DB_NAME).

set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
    echo "ERROR: .env not found at project root." >&2
    exit 1
fi

# shellcheck disable=SC1091
set -a
. ./.env
set +a

DB_CONTAINER="${DB_CONTAINER:-impact_db}"
MIGRATIONS_DIR="db/migrations"

if ! docker ps --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
    echo "ERROR: container '${DB_CONTAINER}' is not running. Try 'make up'." >&2
    exit 1
fi

mysql_in_container() {
    docker exec -i "${DB_CONTAINER}" mysql \
        -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" "$@"
}

# Ensure tracking table exists.
mysql_in_container -e "CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;" 2>/dev/null

shopt -s nullglob
files=("${MIGRATIONS_DIR}"/*.sql)
shopt -u nullglob

if [ "${#files[@]}" -eq 0 ]; then
    echo "No migration files in ${MIGRATIONS_DIR}/  |  Applied: 0  |  Already current: 0"
    exit 0
fi

applied=0
current=0
for file in $(printf '%s\n' "${files[@]}" | sort); do
    version="$(basename "$file")"
    count="$(mysql_in_container -sNe "SELECT COUNT(*) FROM schema_migrations WHERE version='${version}';" 2>/dev/null || echo 0)"
    if [ "${count}" -eq 0 ]; then
        echo "Applying: ${version}"
        mysql_in_container < "${file}"
        mysql_in_container -e "INSERT INTO schema_migrations (version) VALUES ('${version}');"
        applied=$((applied + 1))
    else
        current=$((current + 1))
    fi
done

echo "Applied: ${applied}  |  Already current: ${current}"
