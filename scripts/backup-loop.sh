#!/usr/bin/env bash
# Sidecar loop: run backup.sh every day at BACKUP_AT (local time of the container's TZ, default 02:30).
set -euo pipefail
AT="${BACKUP_AT:-02:30}"

while true; do
  now=$(date +%s)
  next=$(date -d "today $AT" +%s)
  [ "$next" -le "$now" ] && next=$(date -d "tomorrow $AT" +%s)
  echo "[backup-loop] next run at $(date -d "@$next" '+%F %T %Z') (in $(( next - now ))s)"
  sleep "$(( next - now ))"
  /scripts/backup.sh || echo "[backup-loop] backup FAILED" >&2
done
