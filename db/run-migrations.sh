#!/bin/bash
# Impact Score DB — container-side migration runner.
# Launched by entrypoint.sh on every container start. Waits for MySQL to be
# ready, ensures schema_migrations exists, then applies any unapplied files
# from /migrations/ in filename sort order.
set -eo pipefail

MIGRATIONS_DIR="/migrations"

mysql_cmd() {
    mysql -h 127.0.0.1 -u"${MYSQL_USER}" -p"${MYSQL_PASSWORD}" "${MYSQL_DATABASE}" "$@"
}

# Wait for MySQL to accept connections (up to 60s).
attempts=0
until mysqladmin ping -h 127.0.0.1 -u"${MYSQL_USER}" -p"${MYSQL_PASSWORD}" --silent 2>/dev/null; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 60 ]; then
        echo "[migrate] ERROR: timeout waiting for MySQL" >&2
        exit 1
    fi
    sleep 1
done

# Ensure the tracking table exists. init.sql also creates it, so on a fresh
# install this is a no-op; on existing dbs (manual upgrade path) it's needed.
mysql_cmd -e "CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;"

shopt -s nullglob
files=("${MIGRATIONS_DIR}"/*.sql)
shopt -u nullglob

if [ "${#files[@]}" -eq 0 ]; then
    echo "[migrate] No migration files — nothing to do."
    exit 0
fi

applied=0
for file in $(printf '%s\n' "${files[@]}" | sort); do
    version=$(basename "$file")
    count=$(mysql_cmd -se "SELECT COUNT(*) FROM schema_migrations WHERE version='${version}';" 2>/dev/null || echo 0)
    if [ "${count}" -eq 0 ]; then
        echo "[migrate] Applying: ${version}"
        mysql_cmd < "${file}"
        mysql_cmd -e "INSERT INTO schema_migrations (version) VALUES ('${version}');"
        applied=$((applied + 1))
    fi
done

echo "[migrate] Done. Applied: ${applied} migration(s)."
