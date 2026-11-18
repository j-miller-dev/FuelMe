# FUEL
Personal MacroFactor-style adaptive diet app. See `BLUEPRINT.md` and `CLAUDE.md`.

Run engine tests (no Composer needed): `php tests/Engine/run.php`

App: `composer install && npm install && cp .env.example .env && php artisan key:generate && php artisan migrate && composer dev`. Tests: `./vendor/bin/pest`. Deploy/backups: `docs/DEPLOY.md`.
