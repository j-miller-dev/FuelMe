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
- Engine data for the UI is generated: `php tools/export-engine-data.php` writes web-prototype/src/engine-data.json (re-run after any engine change). In the real app these become API calls.
- `GoalPlanner` (PHP, tested): "X kg / +Y kg muscle by date" -> required %BW/wk, verdict, kcal target. TS port `web-prototype/src/goal.ts` is parity-tested against PHP fixtures (`goal-cases.json`); re-export after changing the PHP.
- Muscle goals convert to a weight target via an editable lean-fraction ASSUMPTION; monthly muscle ceilings are an Aragon-style rule of thumb. Never present them as measurements.
- Prototype goal macros come from a 50-kcal grid (`engine-data.json` -> grid), so they can differ from the exact target by up to 25 kcal. The real app calls the engine directly.
- Add flow: `web-prototype/src/screens/AddFood.tsx` (tabs, favourites, latest, basket, "Log N foods"); mock foods in `src/lib/foods.ts` are illustrative, not AFCD. Charts: `src/lib/charts.tsx` (range chips, trend chart with band). UI ideas borrowed from MacroFactor screenshots: layouts and interactions only, never their assets or wording.
- Lifting style: `Settings.style` ('hit' default | 'volume'). Exporter emits plans/grid/adjust keyed `style|...` and `meta.weeks`. HIT lifting = MET 3.5 ESTIMATE, 40 min default; protein constant daily; no carb-loading note for low-volume lifting.
- Progress tabs now include Photos (on-device Dexie v2 `photos` table, `src/lib/photos.ts`, tested with fake-indexeddb; real app = private server storage) and Nutrients (`src/lib/nutrients.ts`). Nutrient targets: only iron 8 mg and zinc 14 mg are verified NHMRC (adult male 19-50); every other row is a US RDA/AI stand-in tagged `src:'US'`. Verify against NHMRC before treating as Australian. Intake there is SAMPLE data.
- Tailwind note: colour token is `canvas`, not `base` (`text-base` is a font-size).
- M0 (Laravel scaffold, Docker, CI, Fortify + passkeys) and M1 not yet done: needs Composer.
