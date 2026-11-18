#!/usr/bin/env bash
# One backup run: pg_dump (custom format) -> verify -> rotate -> optional off-box copy.
#
# Connection comes from the standard libpq variables (PGHOST, PGPORT, PGUSER, PGPASSWORD, PGDATABASE).
# BACKUP_DIR              where dumps live (default ./backups)
# BACKUP_KEEP_DAILY       how many daily dumps to keep (default 14)
# BACKUP_KEEP_WEEKLY      how many Sunday dumps to keep (default 8)
# BACKUP_RCLONE_REMOTE    optional rclone destination, e.g. "hetzner:fuel-backups" (off-box copy)
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-./backups}"
KEEP_DAILY="${BACKUP_KEEP_DAILY:-14}"
KEEP_WEEKLY="${BACKUP_KEEP_WEEKLY:-8}"

mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly"
stamp="$(date +%Y%m%d-%H%M%S)"
partial="$BACKUP_DIR/daily/.fuel-$stamp.dump.partial"
final="$BACKUP_DIR/daily/fuel-$stamp.dump"
trap 'rm -f "$partial"' EXIT

echo "[backup] dumping ${PGDATABASE:-?} -> $final"
pg_dump --format=custom --no-owner --no-privileges --file "$partial"

# A dump we cannot list is not a backup: fail loudly instead of rotating good dumps out.
pg_restore --list "$partial" > /dev/null
[ -s "$partial" ] || { echo "[backup] empty dump" >&2; exit 1; }
mv "$partial" "$final"
trap - EXIT

# Sunday's dump is also kept as the weekly full.
if [ "$(date +%u)" = "7" ]; then
  cp "$final" "$BACKUP_DIR/weekly/$(basename "$final")"
fi

prune() { # dir keep: delete all but the newest <keep> dumps. An empty or missing dir is fine (find, not ls: no glob failure under pipefail).
  { find "$1" -maxdepth 1 -type f -name 'fuel-*.dump' -printf '%T@ %p\n' 2>/dev/null || true; } | sort -rn | tail -n +"$(( $2 + 1 ))" | cut -d' ' -f2- | while read -r old; do
    echo "[backup] pruning $old"; rm -f "$old"
  done
}
prune "$BACKUP_DIR/daily" "$KEEP_DAILY"
prune "$BACKUP_DIR/weekly" "$KEEP_WEEKLY"

if [ -n "${BACKUP_RCLONE_REMOTE:-}" ]; then
  echo "[backup] copying off-box to $BACKUP_RCLONE_REMOTE"
  rclone copy "$final" "$BACKUP_RCLONE_REMOTE/daily/" --quiet
  [ -f "$BACKUP_DIR/weekly/$(basename "$final")" ] && rclone copy "$BACKUP_DIR/weekly/$(basename "$final")" "$BACKUP_RCLONE_REMOTE/weekly/" --quiet
  # Mirror pruning off-box using the same retention windows.
  rclone delete "$BACKUP_RCLONE_REMOTE/daily/" --min-age "${KEEP_DAILY}d" --quiet || true
  rclone delete "$BACKUP_RCLONE_REMOTE/weekly/" --min-age "$(( KEEP_WEEKLY * 7 ))d" --quiet || true
else
  echo "[backup] BACKUP_RCLONE_REMOTE not set: this copy is on the same box only. Set it for real protection."
fi

echo "[backup] ok $final ($(du -h "$final" | cut -f1))"
