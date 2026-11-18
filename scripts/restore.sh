#!/usr/bin/env bash
# Restore a dump INTO the database named by PGDATABASE, replacing its contents.
#   scripts/restore.sh backups/daily/fuel-20261118-023000.dump --yes
# Stop the app/worker/scheduler first (docker compose stop app worker scheduler) so nothing writes mid-restore.
set -euo pipefail

dump="${1:-}"
[ -n "$dump" ] && [ -f "$dump" ] || { echo "usage: $0 <dump-file> --yes" >&2; exit 2; }
[ "${2:-}" = "--yes" ] || { echo "This REPLACES the contents of database '${PGDATABASE:-?}'. Re-run with --yes to confirm." >&2; exit 2; }

pg_restore --list "$dump" > /dev/null || { echo "Not a readable pg_dump custom-format file: $dump" >&2; exit 1; }
echo "[restore] restoring $dump into ${PGDATABASE:-?}"
pg_restore --clean --if-exists --no-owner --no-privileges --single-transaction --exit-on-error --dbname "${PGDATABASE:?PGDATABASE not set}" "$dump"
echo "[restore] done. Start the app again: docker compose start app worker scheduler"
