# Deploying FUEL (Coolify on Hetzner, or any Docker host)

Passkeys, the camera and PWA install all need **HTTPS on a stable hostname**. Coolify/Traefik terminates TLS; the app container speaks plain HTTP on port 80.

## 1. Deploy
1. In Coolify: New Resource -> Docker Compose -> this repo -> `docker-compose.yml`. Attach your domain to the `app` service (port 80).
2. Database: use the Coolify-managed PostgreSQL 18 (not in the compose file). Create a database for FUEL and attach the app to the same Coolify network so its hostname resolves. The DB user must be allowed to `CREATE EXTENSION` (a superuser is fine); the first migration enables `pg_trgm`, `unaccent` and `btree_gin`.
3. Set environment variables in Coolify (see `.env.example`):
   - `APP_KEY` (`php artisan key:generate --show`)
   - `APP_URL=https://your.domain` (the passkey relying-party ID is derived from this host; changing it later breaks existing passkeys)
   - `DB_HOST` (Coolify's internal Postgres hostname), `DB_PORT=5432`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
   - `REGISTRATION_ENABLED=true` for first run only
   The backup service reuses these `DB_*` values and refuses to start if one is missing.
4. Deploy. The `app` container runs migrations on boot. Health check: `/up`.
5. Open the site and create your account (first-run only), then set `REGISTRATION_ENABLED=false` and redeploy. Registration also closes by itself once a user exists.
6. On your phone: Security -> Add a passkey, then browser menu -> Install app.

## 2. Backups
The `backup` service (a `postgres:18` image, matching Coolify's server version) runs `scripts/backup.sh` daily at 02:30 (`FUEL_TIMEZONE`), keeps 14 daily and 8 Sunday dumps in the `backups` volume, and checks each dump is readable.

Set an off-box copy; a backup on the same disk is not protection. Any rclone remote works. S3-compatible example (Hetzner Object Storage), no config file needed:

    BACKUP_RCLONE_REMOTE=hz:fuel-backups
    RCLONE_CONFIG_HZ_TYPE=s3
    RCLONE_CONFIG_HZ_PROVIDER=Other
    RCLONE_CONFIG_HZ_ENDPOINT=https://<region>.your-objectstorage.com
    RCLONE_CONFIG_HZ_ACCESS_KEY_ID=...
    RCLONE_CONFIG_HZ_SECRET_ACCESS_KEY=...

Run one now: `docker compose exec backup /scripts/backup.sh`

## 3. Restore, and prove it works
Prove a dump restores (never touches live data):

    docker compose exec backup /scripts/restore-test.sh                 # newest dump
    RESTORE_TEST_ALLOW_DRIFT=1 docker compose exec backup /scripts/restore-test.sh <older.dump>

Real restore (replaces the live database):

    docker compose stop app worker scheduler
    docker compose exec backup /scripts/restore.sh /backups/daily/fuel-YYYYMMDD-HHMMSS.dump --yes
    docker compose start app worker scheduler

CI runs backup -> restore-test (plus a corrupt-dump rejection) against Postgres 18 on every push.

## 4. Load the food database (first deploy, once)

Search is empty until the food data is imported. In Coolify open the app resource, Terminal, pick the `app` container, and run:

```bash
php artisan foods:import-afcd --download --cleanup
php artisan foods:import-off --download --cleanup
php artisan foods:reindex
```

Details, attribution and how to refresh weekly are in `docs/DATA.md`.
