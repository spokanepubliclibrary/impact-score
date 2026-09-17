#!/bin/bash
# Impact Score DB entrypoint.
# 1. Spawn the migration runner in the background — it waits for MySQL to come
#    up, applies any pending migrations, then exits.
# 2. Hand off to MySQL's own entrypoint as PID 1.
set -eo pipefail

/usr/local/bin/run-migrations.sh &

exec docker-entrypoint.sh "$@"
