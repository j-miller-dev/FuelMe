# FUEL — personal adaptive nutrition app

Single-user, self-hosted PWA. Laravel 12 + Inertia + React 19 + TS + Tailwind v4 + PostgreSQL 16.
Source of truth: `BLUEPRINT.md`. Read it fully before any work; follow its milestones in order (M0 → M10).

## Rules
- Engine code lives in `app/Domain/Nutrition` and is pure PHP: no Laravel/framework imports.
- Engine tests: `php tests/Engine/run.php` must pass before every commit.
- Never let the LLM produce nutrition values; it only parses text. Numbers come from the food database.
- Exercise calories are NOT added to the daily budget (adaptive TDEE already includes training).
- Do one milestone at a time, then stop for review.

## Status
- Engine ported and tested (macro_tests + 40-person TDEE simulation).
- Also pure PHP + tested: `app/Domain/Shopping` (list builder), `app/Domain/Search` (QueryParser, Synonyms); data in `data/`.
- `database/schema.sql`: Postgres reference schema (syntax-checked only, never run). Turn into Laravel migrations in M1.
- `web-prototype/`: Vite + React + Tailwind v4 mock-data UI (Today, Capture review, Progress, Shopping, Onboarding),
  Dexie outbox + PWA. Port screens into resources/js (Inertia) during M1/M3; keep the tokens in src/index.css.
- Tailwind note: colour token is `canvas`, not `base` (`text-base` is a font-size).
- M0 (Laravel scaffold, Docker, CI, Fortify + passkeys) and M1 not yet done: needs Composer.
