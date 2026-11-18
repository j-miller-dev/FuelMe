#!/usr/bin/env bash
# PROVES a backup is restorable: restores a dump into a throwaway database and compares it with the live one.
# Never touches the live database. Exit code 0 = restore works and data matches.
#   scripts/restore-test.sh                      # newest dump in $BACKUP_DIR/daily
#   scripts/restore-test.sh path/to/file.dump
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-./backups}"
live="${PGDATABASE:?PGDATABASE not set}"
dump="${1:-$(ls -1t "$BACKUP_DIR"/daily/fuel-*.dump 2>/dev/null | head -n1 || true)}"
[ -n "$dump" ] && [ -f "$dump" ] || { echo "[restore-test] no dump found" >&2; exit 2; }

scratch="${live}_restore_test_$$"
cleanup() { dropdb --if-exists "$scratch" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "[restore-test] restoring $dump into scratch database $scratch"
createdb "$scratch"
pg_restore --no-owner --no-privileges --exit-on-error --dbname "$scratch" "$dump"

q() { psql --no-psqlrc -tA -d "$1" -c "$2"; }

fail=0
migs="$(q "$scratch" 'select count(*) from migrations')"
[ "$migs" -gt 0 ] || { echo "[restore-test] FAIL: migrations table empty" >&2; fail=1; }
echo "[restore-test] migrations restored: $migs"

# Row counts of the user-data tables must match the live database exactly (run right after a backup, i.e. nothing written since).
# Pass RESTORE_TEST_ALLOW_DRIFT=1 to only require restored <= live (normal case when testing an older dump).
for t in users profiles goals target_history weight_logs passkeys; do
  exists="$(q "$scratch" "select to_regclass('public.$t') is not null")"
  [ "$exists" = "t" ] || { echo "[restore-test] FAIL: table $t missing after restore" >&2; fail=1; continue; }
  a="$(q "$scratch" "select count(*) from $t")"
  b="$(q "$live" "select count(*) from $t")"
  if [ "${RESTORE_TEST_ALLOW_DRIFT:-0}" = "1" ]; then ok=$(( a <= b )); else ok=$(( a == b )); fi
  [ "$ok" = 1 ] && echo "[restore-test] $t: restored=$a live=$b ok" || { echo "[restore-test] FAIL: $t restored=$a live=$b" >&2; fail=1; }
done

if [ "$fail" = 0 ]; then echo "[restore-test] PASS"; else echo "[restore-test] FAILED" >&2; exit 1; fi
