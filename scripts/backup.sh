#!/usr/bin/env bash
# scripts/backup.sh — cron-friendly DB backup
#
# Behavior:
#   * Loads .env from the project root.
#   * Dumps the running db container with --single-transaction --quick --routines
#     --triggers (no table locks; live schema preserved).
#   * Pipes through gzip; writes to db/backups/YYYY-MM-DD_HHMMSS.sql.gz
#   * Applies BACKUP_RETENTION_DAYS (default 30) — older local dumps are deleted.
#   * If BACKUP_REMOTE is set:
#       - s3://bucket/path     → uploads with `aws s3 cp`
#       - user@host:/path or rsync://...  → ships via `rsync`
#   * Exits non-zero on any failure so cron sends an alert.
#
# Cron line example (runs daily at 02:30):
#   30 2 * * * /path/to/impact-score/scripts/backup.sh >> /var/log/impact-backup.log 2>&1

set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
    echo "[backup] ERROR: .env not found at project root." >&2
    exit 2
fi

# shellcheck disable=SC1091
set -a
. ./.env
set +a

DB_CONTAINER="${DB_CONTAINER:-impact_db}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
BACKUP_DIR="db/backups"
STAMP="$(date +%Y-%m-%d_%H%M%S)"
OUTFILE="${BACKUP_DIR}/${STAMP}.sql.gz"

mkdir -p "${BACKUP_DIR}"

if ! docker ps --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
    echo "[backup] ERROR: container '${DB_CONTAINER}' is not running." >&2
    exit 3
fi

echo "[backup] Dumping ${DB_NAME} → ${OUTFILE}"
docker exec "${DB_CONTAINER}" mysqldump \
    --single-transaction --quick --routines --triggers \
    -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" \
    | gzip -c > "${OUTFILE}"

# Sanity check: nonzero-sized file
if [ ! -s "${OUTFILE}" ]; then
    echo "[backup] ERROR: dump file is empty." >&2
    rm -f "${OUTFILE}"
    exit 4
fi

bytes="$(stat -c%s "${OUTFILE}" 2>/dev/null || stat -f%z "${OUTFILE}")"
echo "[backup] Wrote ${bytes} bytes."

# ── Optional off-host ship ───────────────────────────────────────────────────
if [ -n "${BACKUP_REMOTE:-}" ]; then
    case "${BACKUP_REMOTE}" in
        s3://*)
            if ! command -v aws >/dev/null 2>&1; then
                echo "[backup] ERROR: BACKUP_REMOTE is s3 but 'aws' CLI not installed." >&2
                exit 5
            fi
            echo "[backup] Uploading to ${BACKUP_REMOTE%/}/"
            aws s3 cp "${OUTFILE}" "${BACKUP_REMOTE%/}/${STAMP}.sql.gz"
            ;;
        *)
            if ! command -v rsync >/dev/null 2>&1; then
                echo "[backup] ERROR: BACKUP_REMOTE is set but 'rsync' not installed." >&2
                exit 6
            fi
            echo "[backup] Shipping to ${BACKUP_REMOTE} via rsync"
            rsync -a "${OUTFILE}" "${BACKUP_REMOTE%/}/"
            ;;
    esac
fi

# ── Local retention ──────────────────────────────────────────────────────────
echo "[backup] Pruning local dumps older than ${RETENTION_DAYS} days"
find "${BACKUP_DIR}" -type f -name '*.sql.gz' -mtime "+${RETENTION_DAYS}" -delete

echo "[backup] Done."
