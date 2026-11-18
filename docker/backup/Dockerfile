# Backup sidecar: same major version as the database so pg_dump/pg_restore always match the server.
FROM postgres:18
RUN apt-get update && apt-get install -y --no-install-recommends rclone tzdata ca-certificates \
 && rm -rf /var/lib/apt/lists/*
COPY scripts/ /scripts/
RUN chmod +x /scripts/*.sh
ENTRYPOINT ["/scripts/backup-loop.sh"]
