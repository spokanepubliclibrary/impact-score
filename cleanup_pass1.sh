#!/usr/bin/env bash
# cleanup_pass1.sh — run on YOUR machine, not in the Cowork sandbox.
#
# The Cowork sandbox cannot delete files from the Dropbox-mounted folder
# (virtiofs unlink is blocked). This script performs the deletions the
# modernization audit identified, then stages everything via git.
#
# Run from the repo root:
#   bash cleanup_pass1.sh
# Review with `git status` before committing.

set -euo pipefail

cd "$(dirname "$0")"

echo "=== Pass 1: stale '* copy.php' working copies ==="
git rm -f \
  "duplicate_score copy.php" \
  "edit_score copy.php" \
  "view_scores copy.php" \
  "get_forms copy.php" 2>/dev/null || true

echo ""
echo "=== Pass 1: tracked runtime artifacts (log files) ==="
git rm -f error_log.txt error_log_programming_report.txt 2>/dev/null || true

echo ""
echo "=== Pass 1: IIS leftover ==="
git rm -f iisstart.htm 2>/dev/null || true

echo ""
echo "=== Pass 1: bundled phpMyAdmin (my/) ==="
# A separate phpmyadmin container handles DB admin; the in-tree copy is
# a serious supply-chain risk because it's served by Apache from /my/.
git rm -rf my/ 2>/dev/null || true

echo ""
echo "=== Pass 1: tracked uploads (runtime artifacts) ==="
# Real screenshots / CSVs / QR codes were committed. Keep the directory
# tracked via .gitkeep; content lives on a docker named volume instead.
git rm -rf uploads/ 2>/dev/null || true
mkdir -p uploads
touch uploads/.gitkeep
git add uploads/.gitkeep

echo ""
echo "=== Pass 4d: stale SQL artifacts at root (moved into db/) ==="
# These are now folded into db/init.sql and db/seed_dev.sql.
git rm -f "db structure.sql" dummy_data.sql custom_fields_migration.sql 2>/dev/null || true

echo ""
echo "=== Stage modernization artifacts ==="
git add -A

echo ""
echo "✓ Cleanup complete. Review with: git status"
echo ""
echo "REMINDER: secure/db_connection.php previously contained real production"
echo "credentials. They are still in git history. Rotate the DB password on"
echo "splsqldb1.spokanelibrary.org NOW, regardless of any history rewrite plan."
