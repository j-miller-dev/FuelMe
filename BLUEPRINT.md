# FUEL — Personal Nutrition & Performance App: Build Blueprint

> Single-user, self-hosted, adaptive nutrition tracker. Think "the good parts of MacroFactor + Cronometer, built for Australia, tuned for a BJJ + lifting athlete". Hand this whole file to Claude Code at the repo root. Appendices A–C contain **tested reference code** (PHP) for the calculation engine: port it, don't reinvent it.

**Working name:** FUEL (rename freely). **Owner:** Jason, Melbourne (`Australia/Melbourne`). **Users:** 1 (maybe a partner later; keep `user_id` on every table).

---

## DECISION LOG
- **2026-11-13: Personal web app (PWA) only.** No App Store / Play Store, no other users, no monetisation. **Skip Section 14** (parked for reference; do not build any of it). Keep `user_id` on tables so a partner profile stays possible, but do NOT build RLS, per-user AI quotas, billing, account-deletion flows, social login, or store wrappers.
- Therefore: passkeys + password fallback is enough; registration closed after first user; Tailscale-only or HTTPS public both fine. Priority shifts to **speed, polish and offline reliability on your phone** (install to home screen, fast cold start, instant search, offline logging).

---

## 0. How to use this file with Claude Code

1. Save as `BLUEPRINT.md` in an empty repo. Create `CLAUDE.md` containing the **Rules** below (section 0.2).
2. Start with:
   > Read BLUEPRINT.md fully. Do **Milestone M0 and M1 only**. Follow the acceptance criteria. Commit when green. Stop and summarise before M2.
3. One milestone per session. After each, run the full test suite and the deploy smoke test.

### 0.1 Definition of done (every milestone)
Tests green (Pest + engine tests + Playwright smoke) · no TODOs left in shipped code · mobile (390×844) screenshot checked · lighthouse PWA ≥ 90 · migrations reversible · README updated.

### 0.2 Rules for CLAUDE.md
- Stack is fixed: Laravel 12, PHP 8.3, Inertia + React 19 + TypeScript (strict), Tailwind v4, shadcn/ui (Radix), PostgreSQL 16. Don't swap libraries without asking.
- **The calculation engine is pure PHP with zero framework imports** (`app/Domain/Nutrition/*`). Controllers call services; services call the engine. Engine changes require engine tests.
- **Nutrition numbers never come from an LLM.** LLMs only parse language and rank candidates; every calorie/macro comes from a database row.
- Store a **snapshot** of macros on every food log (editing a food must never rewrite history).
- All dates are `local_date` in the user's timezone; store UTC timestamps as well.
- Every list/search endpoint must be fast on a phone over 4G: p95 < 150 ms server-side for food search.
- Mobile-first. If it needs more than 2 taps to repeat a previous food, it's wrong.
- Not medical advice. Keep safety guardrails (section 9.7) always on.

---

## 1. Product principles

1. **Speed beats completeness.** Logging a repeated food = 1–2 taps. A whole new meal = say it out loud.
2. **Trust the algorithm, show the working.** Every number (target, TDEE, trend) has a "why" sheet.
3. **Australian first.** AU foods, AU brands, AU supermarkets, kJ/kcal toggle, metric by default.
4. **Beautiful and calm.** Dark-first, big numerals, one accent colour, motion that explains state.
5. **Your data stays yours.** Self-hosted, exportable (CSV/JSON), nightly backups.

---

## 2. Feature list (priority)

**P0 (v1)**
- Today dashboard: calories/macros remaining, rings + bars, day-type badge (Rest/Light/Moderate/High), peri-workout card.
- Fast logging: search (local, instant), recents/frequents by time of day, copy yesterday / copy meal, swipe to repeat, quick-add raw macros, serving stepper.
- **AI Meal Capture** (voice or text → matched list → quick swap) — section 6.
- Barcode scan (AU) with cache + fallback to label capture — section 5.
- Custom foods + custom meals/recipes (templates, scale servings, tag pre/post-workout).
- **Recipe multi-select → shopping list** (pick several saved recipes, set servings for each, get one merged list with Need/Have, check-off in store) — section 5.6.
- Weight logging (smart scale/manual) + trend line (Kalman) — section 9.1.
- Adaptive expenditure (TDEE) + weekly check-in with new targets — section 9.
- Exercise logging with presets (BJJ, lifting, HIIT, Norwegian 4×4, custom) → scientific energy estimate — section 7/9.
- Macro planner with carb periodisation by training day + safety guardrails — section 9.4–9.7.
- Pre/during/post workout fuelling targets + meal suggestions from *your* foods — section 9.8.
- Single-user auth with passkeys, PWA install, offline logging queue.

**P1**
- Import history from MacroFactor/Cronometer/Hevy CSV exports (warm-starts the Kalman filter → calibrated TDEE on day one). Inspect real exports in `/samples` before writing parsers; do not guess formats.
- Weekly insights, adherence streaks, protein-distribution view, fibre/sodium/sat-fat flags, micronutrient view (AFCD has up to 268 nutrients per food).
- Photo of nutrition panel → structured food (Claude vision), optional.
- Goal-by-date mode (e.g. event weight) with safe-rate validation.
- Undereating nudges (section 9.9).

**P1 (training companion, Milestone M10)**
- **Imperfect-day mode** ("good enough day"), weekly-consistency scoring, forgiving streaks — section 9A.1.
- **AI coach chat** grounded in your own data, **weekly written review**, **voice workout logging** — 9A.2–9A.4.
- **Training sync** (Todoist schedule, Hevy CSV, Polar H10 heart rate via Web Bluetooth) — 9A.5.
- **Morning check-in + personal insights**, **event mode** — 9A.6–9A.7.

**P2**
- Diet-break / refeed scheduling, body-fat tape estimate, readiness check-ins, widgets/shortcuts, partner profile, Apple/Google Health sync via companion shortcut or native wrapper (see 9A.5: Health Connect has no web API).

---

## 3. Stack, architecture, deployment

- **Backend:** Laravel 12 (Fortify for auth, Sanctum not needed), Postgres with `pg_trgm`, `unaccent`, `btree_gin`; optional `pgvector` for semantic food matching (P1).
- **Frontend:** Inertia + React + TS, Tailwind v4, shadcn/ui, Framer Motion, Recharts (or visx for the trend/expenditure chart), `vaul` for bottom sheets, `cmdk` style command palette for search, `dexie` for the offline outbox, `vite-plugin-pwa`.
- **Queue/cache:** Redis (or database driver to keep it light). Queue for imports and AI calls.
- **AI:** Anthropic API (server-side only). Parser/reranker on a fast small model (Haiku class), vision on a stronger model. Model IDs in `.env` (`ANTHROPIC_PARSE_MODEL`, `ANTHROPIC_VISION_MODEL`). Monthly spend cap setting. Feature must degrade gracefully with no key (rule-based parser, section 6.7).
- **Deploy:** Docker image (php-fpm + nginx or FrankenPHP) → **Coolify on Hetzner**. Services: app, queue worker, scheduler, postgres, redis. HTTPS via Coolify/Traefik (camera + passkeys + PWA all require HTTPS). Health endpoint `/up`. Optional: restrict to Tailscale.
- **Backups:** nightly `pg_dump` (+ weekly full) to off-box object storage; restore script documented and **tested in M0**.
- **Auth ("easy login"):** Fortify with **passkeys (WebAuthn)** as primary + password fallback; 1-year remember cookie; registration disabled after first user (`REGISTRATION_ENABLED=false`); throttle login; 2FA optional.
- **Timezone:** default `Australia/Melbourne`; day rolls over at a configurable hour (default 03:00) so late-night eating lands on the right day.

### 3.1 Code layout
```
app/Domain/Nutrition/   ExpenditureFilter, TargetCalculator, MacroPlanner, PeriWorkout, ExerciseCalculator  (Appendix A–C, pure PHP)
app/Domain/Foods/       FoodSearch, BarcodeLookup, ServingResolver, AliasLearner
app/Domain/Capture/     MealParser (LLM), CandidateRetriever, Reranker, CaptureSession
app/Domain/Training/    WorkoutLogger, DayTypeResolver
app/Console/Commands/   foods:import-afcd, foods:import-off, foods:reindex
resources/js/Pages/     Today, Add, Capture, Scan, Foods, Meals, Weight, Train, Progress, Plan, Settings
tests/Engine/           ported engine tests + simulation
tests/Eval/             capture golden set (section 6.9)
```

---

## 4. Design system ("looks amazing")

- **Theme:** dark-first (`#0B0D10` base, `#12161B` surface, `#1A2027` raised), light theme supported. One accent (electric mint `#4DF0B0`), macro colours consistent everywhere: **Protein** blue `#5B9DFF`, **Carbs** amber `#FFB547`, **Fat** rose `#FF6B8A`, **Calories** accent. Check contrast ≥ 4.5:1.
- **Type:** Geist Sans + Geist Mono. **Tabular numerals everywhere numbers change.** Big hero numerals (48–64 px) for remaining calories.
- **Layout:** bottom tab bar (Today · Log · Plan · Progress) with a centre **+** FAB that opens the Add sheet (Search · Speak · Scan · Quick-add · Meals). Safe-area insets handled. Thumb-reachable controls.
- **Motion:** spring physics, 150–250 ms; rings animate on log; haptic tap via `navigator.vibrate` where supported; skeletons not spinners; optimistic UI for all logging.
- **Signature screens:** (1) Today — calorie ring + three macro bars + day-type chip + "next up" peri-workout card. (2) Capture review — stacked cards with confidence chips and swap buttons. (3) Progress — scale dots + trend line + expenditure line sharing one x-axis, with the weekly check-in card.
- **Empty/edge states** designed: first-run onboarding (sex, DOB, height, weight, body fat optional, activity, goal), "calibrating" state for TDEE (show uncertainty band, not a false-precise number).
- **Accessibility:** focus rings, reduced-motion respect, 44 px targets, screen-reader labels on rings.

---

## 5. Food data & search (Australia)

### 5.1 Sources and how to use each
| Source | Use | Notes (verified) |
|---|---|---|
| **AFCD Release 3** (FSANZ) | Whole/raw/generic foods, cooked variants, deep nutrients | 1,588 foods, up to 268 nutrients each; Excel downloads (food details, nutrient profiles, recipes…) at foodstandards.gov.au. Free. An aggregator lists licence CC BY 4.0 with attribution "Food Standards Australia New Zealand" — **check the licence text in the download and show attribution in Settings → About**. Multiple energy columns exist (e.g. with/without fibre); inspect the sheet and pick one consistently, record which. |
| **Open Food Facts (OFF)** | Packaged products + barcodes, AU subset | Rate limits: ~100 req/min product reads, **10 req/min search**, 2 req/min facets. **Custom User-Agent required** (e.g. `FUEL/1.0 (contact email)`). **v2 has no full-text search** → you MUST index locally. Licence ODbL → attribution + share-alike on the database. Use periodic data exports for bulk; live API only for barcode misses. |
| **User foods** | Anything missing, your own recipes | Highest rank. |
| *(Optional later)* FatSecret/other paid APIs | Gap fill | Only if OFF AU coverage proves poor in the golden-set eval (6.9). |

Supermarket own-brand coverage (Aldi/Coles/Woolworths) in OFF is **unknown until measured**: build the eval first, then decide. The alias + user-food system (6.6) is the real answer to gaps.

### 5.2 Ingestion
- `foods:import-afcd {path}` — reads the Excel files from `storage/imports/afcd/`, maps to `foods` (+ `food_nutrients` for the long tail), kJ→kcal (÷4.184), per-100 g/mL, flags `state` (raw/cooked/dry) from the name, builds household servings where the data allows.
- `foods:import-off` — imports the **AU-filtered** OFF export on a schedule (weekly), stores `barcode`, `brand`, `stores[]`, `categories`, nutrients per 100 g, serving size, image URL. **Validate** each row: reject if |kcal − (4P+4C+9F)| > 20% and kcal > 50, nonsense values, or missing macros; keep a `quality` score and a `needs_check` badge for crowd-sourced rows.
- Always cache any live OFF barcode hit forever in `foods` (never re-hit).

### 5.3 Search (local, hybrid, fast)
- Postgres: `tsvector` (weighted: name A, brand B, categories C) + `pg_trgm` similarity + `unaccent`. GIN indexes. Target p95 < 150 ms.
- **AU synonym table** applied at query time (capsicum↔bell pepper, mince↔ground beef, rockmelon↔cantaloupe, zucchini↔courgette, coriander↔cilantro, chips↔fries/crisps context, Up&Go↔"Sanitarium Up & Go", Weet-Bix↔weetbix, "yoghurt"↔"yogurt", etc. Make it a data file the user can extend).
- **Natural quantity parsing in the search box:** `150g chicken breast`, `2 eggs`, `1 cup rice`, `300ml milk` pre-fill the portion.
- **Ranking:** `score = 0.40·trigram + 0.20·tokenMatch(name+brand) + 0.20·personal(useCount decayed, recency, same-time-of-day boost) + 0.10·sourceWeight(user 1.0, AFCD .8, OFF-AU .6) + 0.10·dataQuality`, minus penalty for missing macros. Personal aliases (6.6) override.
- Empty query → show **Frequent now** (time-of-day aware), Recents, Favourites, Meals.

### 5.4 Barcode scanning
- Use native `BarcodeDetector` where present; otherwise a WASM fallback (`zxing-wasm` ponyfill) so iOS Safari works. Formats: EAN-13, EAN-8, UPC-A, QR ignored. Requires HTTPS. Torch toggle, haptic on success, continuous-scan mode ("scan 5 items fast").
- Flow: scan → local cache hit → OFF live lookup (UA header, cached) → **not found** → "Add it": (a) type panel values, or (b) **photo of nutrition panel → Claude vision extraction → editable form** → saved as user food (optionally contribute back to OFF later).
- Always show the per-serve and per-100 g panel and a confirm step for `needs_check` items.

### 5.5 Custom foods & meals
- Food form: name, brand, store tag, serving label+grams, per-100 g or per-serve entry (convert), optional fibre/sugar/sat fat/sodium. Duplicate warning via trigram.
- **Meals/recipes:** ingredient list with grams, yield (portions or total weight), cook-loss factor (raw→cooked weight), computed macros per portion; "log 1 portion / log 250 g / scale ×1.5"; tags `breakfast, pre, post, prep, snack`; edit propagates only to *future* logs.
- **Meal-prep mode:** make a batch (e.g. 8 portions), log portions from it; track remaining portions.

### 5.6 Recipes → Shopping list (multi-select, servings, check-off)

**User story:** On the Recipes screen I tap **Select**, tick four saved recipes, adjust servings for each (e.g. 4 → 6), and tap **Make list**. I get one merged list grouped by supermarket aisle. Things I already have are ticked off into a **Have** section; the rest are **Need**. In the shop I tick items as I go. Changing a recipe's servings later rescales the list without losing what I've already ticked.

**Flow**
1. **Select mode** on Recipes/Meals: checkbox on each card (long-press also starts selection), sticky tray at the bottom: "4 recipes · 18 serves" with a chevron to expand.
2. **Tray / servings:** each selected recipe shows a stepper (− / +, step 1; long-press for ½) defaulting to its saved yield. Show the plan's total macros per day-equivalent against targets ("meal-prep week: 4 recipes ≈ 3,050 kcal/day · protein 158 g") so the list doubles as a meal-prep check.
3. **Make list** → `POST /api/shopping-lists {recipes:[{mealId, servings}], name?}` → list screen.
4. **List screen:** two tabs/sections **Need** and **Have**; grouped by aisle (Produce, Meat & seafood, Dairy & eggs, Bakery, Pantry, Frozen, Drinks, Other). Each row: checkbox · name (+ brand/variant if the recipe pins one) · quantity · small chips showing which recipes use it. Swipe right = tick/untick, swipe left = edit/delete. Big touch targets, strike-through + haptic on tick, progress bar ("12 of 31"). Works fully **offline** (in-store reception is bad).
5. **Pantry:** items marked "I have this" become `pantry_items`; staples (salt, oil, spices, flour) can be flagged **always have** and are auto-moved to Have on every new list. Optional quantities ("have ~200 g") subtract from Need.
6. **Done:** archive the list; optionally add everything ticked as bought to the pantry (with expiry hint for fresh items, P2).

**Aggregation algorithm (deterministic, unit-tested)**
- `scale = chosenServings / recipe.yieldServings`. Each ingredient quantity × `scale`.
- Normalise every line to a canonical **ingredient key** = `food_id` when linked, otherwise a normalised name (lowercase, singularised, AU synonyms: capsicum/bell pepper, mince/ground beef, etc.). Store ingredients as **as-purchased** (raw weight), applying the recipe's cook-loss only for macro maths, never for shopping.
- Convert to a base unit (g, ml, or count) via the food's `food_servings`/density; merge identical keys by summing. Mixed incompatible units (e.g. "1 onion" + "200 g onion") convert through the food's average unit weight; if impossible keep two lines grouped under one heading.
- "To taste" / "pinch" / "splash" lines have no quantity: list once as a staple, never summed.
- **Pack-size rounding** (optional per user): if the linked product has a pack size (OFF `quantity` or user-entered), show `Need 640 g → 2 × 500 g` and let the user choose exact vs rounded-up.
- **Aisle mapping:** food `category` → aisle via a data file; user can drag-reorder aisles per store (Aldi/Coles/Woolworths layouts differ) and the order is remembered.
- **Idempotent regeneration:** items are keyed by `ingredient_key`. Changing servings/adding/removing a recipe recomputes quantities but **preserves state** (`need|have|bought`) and manual items. If a recipe was edited after list creation, show a "Recipes changed — refresh list" banner (never silently change a list mid-shop).
- **Manual items** can be added at any time ("toilet paper") and are never touched by regeneration.

**Sharing/export:** copy as text, share sheet, and a one-tap **send to Todoist** (project + section per aisle) behind an integration setting (P1). Printable view (P2).

**Data model additions:** `shopping_lists(id,user_id,name,status[active|done],created_at)` · `shopping_list_recipes(list_id,meal_id,servings)` · `shopping_list_items(list_id,ingredient_key,food_id?,label,qty,unit,aisle,state[need|have|bought],source_meal_ids jsonb,is_manual,sort)` · `pantry_items(user_id,ingredient_key,food_id?,qty?,unit?,is_staple,updated_at)` · `store_aisle_orders(user_id,store,aisle_order jsonb)`.

**Acceptance tests:** (1) three recipes sharing onion/garlic/olive oil merge to one line each; (2) servings 4→6 scales by 1.5× exactly; (3) pantry subtraction leaves the correct remainder and never goes negative; (4) tick states survive a servings change; (5) "1 onion" + "200 g onion" merge via unit weight; (6) offline: create list, tick items, reconnect → synced with no duplicates; (7) pack rounding 640 g → 2 × 500 g; (8) a recipe edited after list creation shows the refresh banner and does not alter the list until confirmed.

---

## 6. AI Meal Capture — say or write a whole meal, review matches, swap in one tap

**User story:** I hold the mic and say *"two scrambled eggs, two slices of Burgen toast with butter, Jalna Greek yoghurt about 200 grams with honey, and an Aldi porterhouse around 250 grams cooked."* In ~3 seconds I see five cards, each with its best database match and a one-tap **Swap**. I fix the one that's wrong and tap **Log all**.

### 6.1 Pipeline
```
voice/text → (1) PARSE (LLM, structured) → (2) RETRIEVE candidates (local DB, hybrid)
          → (3) RESOLVE portion → (4) SCORE + (optional) LLM RERANK → (5) REVIEW UI → (6) LOG + LEARN
```
1. **Input.** Hold-to-talk using the Web Speech API where available (Chrome/Android); otherwise the keyboard's native dictation into the same textarea (always works). The text box is always editable — voice is just fast typing. Support multi-meal text ("for lunch… then for dinner…").
2. **Parse (LLM, temperature 0, forced JSON tool schema).** Extracts one object per food. The model **never outputs nutrition values**. Prompt includes: current local time, meal-slot hint, AU vocabulary note, and the user's **top 40 personal foods + aliases** (so "my usual protein shake" or "the usual oats" resolves). Schema:
```json
{
  "items": [{
    "phrase": "Jalna Greek yoghurt about 200 grams",
    "name_query": "greek yoghurt natural",
    "brand": "Jalna",
    "store": null,
    "state": "as_sold|raw|cooked|dry|null",
    "quantity": 200, "unit": "g|ml|piece|slice|cup|tbsp|tsp|serve|can|bar|handful|null",
    "grams_estimate": 200,
    "meal_slot": "breakfast|lunch|dinner|snack|null",
    "is_approximate": true,
    "ambiguity": ["flavour not stated"],
    "components": []
  }],
  "unparsed": []
}
```
   - Compound dishes ("chicken parma with chips") → `components` the model proposes; UI shows them as one expandable card.
   - Brands/stores: "Aldi", "Coles", "Woolies/Woolworths", "IGA" → `store`; brand words (Jalna, Burgen, Bega, Sanitarium, Uncle Tobys, Tim Tam/Arnott's, Up&Go, Milo, Vegemite, Weet-Bix…) → `brand`.
3. **Retrieve (per item).** Hybrid query: filter/boost by brand and store, trigram + tsvector on `name_query`, personal alias lookup first, then user foods, AFCD, OFF-AU. Return top 8 candidates with component scores. For raw-vs-cooked ambiguity return *both* states.
4. **Resolve portion.** Convert (quantity, unit) → grams using, in order: explicit grams/ml → the food's own `food_servings` (egg=1 piece, slice, can, bar) → household-measure table (cup/tbsp/tsp by food category & density) → typical-portion table (handful, serve) → category default. Mark `portion.estimated=true` when not explicit and show a ± hint. Cooked/raw weights use the recipe cook-loss factors from AFCD where available.
5. **Score → confidence.** `match = 0.45·textScore + 0.25·brandStoreMatch + 0.15·personalBoost + 0.10·stateMatch + 0.05·quality`. `confidence = f(top match, gap to #2, brand certainty, portion certainty)` → **green** (auto-selected, confident), **amber** (picked but check), **red** (no good match — user must choose/create). Only call an **LLM rerank** (small model, ≤8 candidates, returns chosen id + one-line reason) when top-2 gap < threshold; cache by `(phrase, candidate set hash)`.
6. **Review UI** (see 6.2). **Log + learn** (6.6).

### 6.2 Review screen (the quick-swap UX)
- Header: the raw transcript (editable), "re-parse" button.
- One **card per item**: phrase chip · chosen food (name, brand, store badge, source badge AFCD/Brand/Mine) · portion stepper (g, plus natural unit) · macros for that portion · confidence dot.
- **Swap** (single tap) → bottom sheet:
  1. Top 5 alternatives with reason chips ("brand match", "Aldi", "cooked", "you picked this before"),
  2. **Raw ↔ cooked** and **generic ↔ branded** toggles (instantly re-picks best in that state),
  3. Inline search box (prefilled with the phrase), 4. **Scan barcode**, 5. **Create new food**, 6. **Skip item**.
- Sticky footer: totals vs *remaining today* (macros turn red if blowing the budget), meal-slot selector, **Log all**. Undo toast after logging.
- Red cards sort to the top; amber next; green collapsed by default.
- Everything works with one thumb; Swap sheet opens in < 100 ms because candidates are already in the payload.

### 6.3 API
`POST /api/capture/parse` `{ text, mealSlotHint?, now, tz }` →
```json
{ "sessionId": "uuid",
  "items": [{ "id":"i1","phrase":"...","parsed":{...},
    "candidates":[{"foodId":123,"score":0.91,"why":["brand","store"]}],
    "chosen":{"foodId":123,"grams":250,"state":"cooked","estimated":false},
    "confidence":"green|amber|red" }],
  "totals":{"kcal":0,"p":0,"c":0,"f":0} }
```
`POST /api/capture/{sessionId}/swap` `{itemId, foodId}` · `POST /api/capture/{sessionId}/commit` `{items:[...], mealSlot, loggedAt}` (idempotency key required).

### 6.4 Brand & store handling (examples that must work)
- *"aldi porterhouse"* → store=aldi + "porterhouse steak". If an Aldi-tagged row exists, rank it first; otherwise fall back to AFCD generic porterhouse (raw/grilled) with a visible note **"generic cut — Aldi not in database"** and a one-tap *Create Aldi version* (pre-filled from generic, user edits macros once, then it's remembered).
- *"Jalna greek yoghurt"* → brand=Jalna, then choose between natural / vanilla / fat-free variants; if flavour unspecified, prefer the user's previously chosen variant (alias), else show amber with the variants as top swap options.
- *"Up&Go"*, *"Weet-Bix 2"*, *"Tim Tams x3"*, *"a flat white"* (milk type default from user setting), *"Coles mince"* (→ mince, fat % ambiguity chip: 5-star/10%/20%).
- Quantities: *"2 slices"*, *"a can of tuna"*, *"a handful of almonds"*, *"half an avocado"*, *"300 ml"*, *"a tablespoon of peanut butter"*.

### 6.5 Voice details
- Hold-to-talk button with live waveform and live transcript; release to parse. Show partial transcript while speaking.
- Language `en-AU`. Post-process common ASR errors with a replace map (e.g. "grease yoghurt"→"Greek yoghurt", "port a house"→"porterhouse") built from the user's own food names; the parse prompt also receives the personal food list to bias the model.
- Privacy: audio is handled by the browser's speech engine; only the **text** is sent to the server and onward to the model.

### 6.6 Learning from swaps (the feature that makes it feel magical)
- Table `food_aliases(user_id, phrase_norm, food_id, state, default_grams, hits, last_used)`. When the user swaps or confirms, upsert alias `normalised phrase → food` (+ their usual portion). Next time `"porterhouse"` or `"yoghurt"` resolves to *their* Aldi porterhouse / Jalna tub automatically and turns green.
- Time-of-day and meal-slot aware ("coffee" at 7am → flat white; at 3pm → same).
- "Forget this match" in Settings → Aliases (list, edit, delete).

### 6.7 Offline / no-API fallback
Rule-based parser: regex for `qty unit of? name`, split on commas/"and"/"with"/newlines, then the same retrieval + review UI. Lower accuracy, but always works. Banner: "Basic mode (AI unavailable)".

### 6.8 Safety, cost, latency
- Never auto-log without a review unless **Auto-accept when all green** is enabled in Settings (default off).
- Budget: parse ≤ 1.5 s, retrieval ≤ 300 ms, end-to-end ≤ 3 s typical. Cache parses by normalised text. Monthly token/cost cap with a visible meter.
- Prompt-injection: treat transcript as data; the model has no tools except the output schema; validate the JSON strictly; clamp quantities to sane bounds (e.g. ≤ 3 kg per item) and flag outliers.

### 6.9 Evaluation harness (required — this is how we know it's "advanced")
- `tests/Eval/golden.yaml`: ≥ 100 Australian phrases → expected `food_id` (or acceptable set) + expected grams ± tolerance. Include the brand cases above, cooked/raw ambiguity, compound dishes, and noisy ASR-style text.
- Metrics printed by `php artisan capture:eval`: **top-1 match accuracy, top-3 recall, quantity accuracy (±15%), % items needing swap, p50/p95 latency, cost per parse.**
- Targets (after AFCD+OFF import and a week of aliases): top-1 ≥ 80%, top-3 ≥ 95%, quantity ±15% ≥ 90%. Run in CI against a fixed DB snapshot; fail the build on regression > 3 points.
- Use the eval to decide whether OFF AU coverage is good enough or a paid data source is justified.

---

## 7. Exercise logging

- One-tap presets: **BJJ** (light/standard/hard), **Heavy lifting**, **HIIT**, **Norwegian 4×4**, plus custom. Each preset has default duration; user edits minutes and intensity (or enters RPE).
- Energy = **net** kcal `(MET − 1) × kg × hours` using the 2024 Compendium (and ABS 7.5 for judo/jujitsu); show ±25% range; user can override with a wearable value. See Appendix B for the catalogue and sources.
- Scheduling: a **weekly training template** (e.g. BJJ Mon 06:30 · lifting Mon/Wed/Fri midday · BJJ Tue/Thu 11:30 · Sat 10:00; Friday 4×4). The planner uses the template for forecasting; **logged sessions override** the template for that day and trigger `replanRemainingDays()` (keeps the weekly total).
- Optional Hevy CSV import to back-fill sessions (P1).
- **Lifting style setting** (`style`: `hit` default | `volume`). HIT (Mentzer-style) = few sets to failure, long rests, so session-average MET is low (3.5, Compendium 02054 closest code, flagged ESTIMATE; ~40 min default, ~120 kcal net at 74 kg). Effects: lifting-only days get no extra carbs (BJJ/conditioning days drive carb periodisation); no carb-loading note for low-volume lifting (mixed meal with ~0.3-0.4 g/kg protein 1-3 h before); protein identical every day including rest days. Weekly kcal still conserved. Planner, grid and adjust tables are keyed by style.

---

## 8. Data model (Postgres)

Keep `user_id` + `created_at/updated_at` on all. Key tables:
- `users`, `profiles` (sex, dob, height_cm, tz, units, rollover_hour, body_fat_pct?, training_age, activity_level, carb_mode, protein_g_per_kg_override?)
- `foods` (id, source[user|afcd|off|recipe], source_id, barcode?, name, brand?, stores text[], category, state[raw|cooked|dry|as_sold], per100_kcal/protein/carbs/fat/fibre/sugar/sat_fat/sodium, is_liquid, density_g_ml?, quality, needs_check, tsv, last_used_at, use_count) + `food_servings` (food_id, label, grams) + `food_nutrients` (long tail) + `food_aliases` (6.6)
- `meals` (+ `meal_items`, yield, cook_loss, tags[])
- `food_logs` (local_date, logged_at, meal_slot, food_id?, meal_id?, grams, **kcal/protein/carbs/fat/fibre snapshot**, source[search|scan|capture|quick|copy], capture_session_id?, idempotency_key)
- `weight_logs` (local_date, kg, source) · `body_measurements`
- `workout_types`/`workout_logs` (type, intensity, minutes, rpe?, met, net_kcal, started_at, source)
- `training_template` (dow, start_time, type, intensity, minutes)
- `goals` (phase[cut|maintain|bulk], rate_pct_per_week, start_date, event_date?, experience)
- `engine_snapshots` (local_date, trend_kg, tdee, tdee_sd, filter_version) — recomputed nightly and on edits to past data
- `target_history` (effective_from, kcal, protein, fat, carbs, reason, plan_json)
- `capture_sessions` (transcript, parsed_json, outcome, latency_ms, cost_usd) for the eval/telemetry
- `settings` (JSON)

---

## 9. The calculation engine (detailed spec)

**Principle:** layered, deterministic, explainable, conservative. Each layer has tests. Reference code: Appendices A–C (all tests pass).

### 9.1 Layer 1 — Trend weight + adaptive expenditure (Kalman)
Joint filter over `[trend weight W, expenditure E]`:
`W[t+1] = W[t] + (intake[t] − E[t]) / 7700` · `E` follows a slow random walk · scale reading = `W + noise`.
- Missing weigh-in → skip update. Missing intake → assume intake = E with wide uncertainty. Water spikes → outlier down-weighting (z > 2.5 inflates measurement noise).
- Warm start from a formula prior with wide uncertainty (σ ≈ 350 kcal); posterior σ is exposed as `tdeeSd` → UI shows "calibrating" until σ < ~150.
- Defaults tuned by simulation (Appendix A test): on 40 simulated people with noise, misses and an activity drop, error ≈ 120 kcal (weeks 3–6) vs ≈ 490 for the naïve formula. **Re-run the simulation when changing any constant.**
- `7700 kcal/kg` is configurable. P2: composition-aware energy density (Hall-type) for large changes.
- Recompute the whole history nightly and whenever past logs change (cheap: O(days)).

### 9.2 Layer 2 — Day-one prior (before data exists)
`RMR`: Mifflin-St Jeor, or **Katch-McArdle/Cunningham if body fat is known**. `TDEE_prior = RMR × PAL_non-exercise + avg weekly exercise kcal/7`. Occupation matters (a high-NEAT job like warehouse/linehaul work needs a higher PAL than a desk job — ask in onboarding). The prior only seeds the filter; the filter takes over as data arrives.

### 9.3 Layer 3 — Goal, rate and weekly calorie target
- Goal rate as **% bodyweight per week** (negative = loss). Defaults and caps (sources in 9.10):
  - **Bulk:** novice ≈ 0.5, intermediate ≈ 0.25–0.35, advanced ≈ 0.1–0.25 %/wk; surplus ≈ 10–20% of TDEE as a sanity bound (warn if > 20%).
  - **Cut:** 0.5–1.0 %/wk; default 0.5 when lean (< ~12–15% BF), hard cap 1.0.
- `target = TDEE + rate% × trendKg × 7700 / 7`, then **smoothed**: move 60% of the way to the ideal each week, max ±150 kcal/week, never below the safety floor (9.7). (`TargetCalculator::weeklyTarget`.)
- Hold (don't change target) when adherence is poor or data is insufficient (9.9).

### 9.4 Layer 4 — Macro hierarchy (strict order)
1. **Safety floor** (9.7).
2. **Protein** (g/kg BW, or g/kg FFM when lean and cutting): bulk/maintain default 1.8 g/kg (range 1.6–2.2); cut `max(2.0·BW, 2.3·FFM)` (range up to ~3.1 g/kg FFM); user override allowed (e.g. 2.2 g/kg). Cap protein at 35% of calories. **Per-meal dose** ≈ 0.4 g/kg (clamped 20–50 g) spread over 3–6 feedings every ~3–4 h; pre-sleep 30–40 g casein optional.
3. **Fat:** floor `max(0.6 g/kg, 15% kcal)`; target share ≈ 25–27% kcal; ceiling 1.5 g/kg.
4. **Carbohydrate = remainder**, then periodised (9.5). Modes: `flexible` (default), `cap` (user ceiling, remainder to fat), `keto` (30 g carbs, with a training-conflict warning).
5. **Fibre** ≈ 14 g per 1000 kcal (heuristic target; show as guidance, not a hard rule).

### 9.5 Layer 5 — Carb periodisation by training day (weekly calories conserved)
- Classify each day from planned/logged sessions: effective moderate-high minutes → `rest / light / moderate / high / very_high`.
- Carb range per class (g/kg/day, ACSM/Burke): light 3–5 · moderate 5–7 · high 6–10 · very high 8–12.
- Allocate the week's carb total across days **proportionally to class midpoints, blended toward flat** (`periodisation` k = 0.6), then clamp each day into its range with water-filling so the **sum stays exact**. Protein and fat stay constant; calories vary only via carbs.
- **Do not add exercise calories to the daily budget.** The adaptive TDEE already reflects typical training; adding them double-counts. Exercise energy is used for (a) day classification, (b) energy-availability checks, (c) peri-workout sizing, (d) display. Provide an opt-in "eat back X%" only for people who want it (default off).

### 9.6 Layer 6 — Replan on the fly
If a session is added/removed today, recompute remaining days so the weekly total is preserved; show a small "Today +120 kcal because you added 4×4" explanation.

### 9.7 Layer 7 — Safety guardrails (always on)
- **Energy availability** `EA = (kcal − exercise kcal) / FFM`. Hard floor: weekly target never below `30 × FFM + avg exercise kcal` (IOC RED-S: ~45 kcal/kg FFM is adequate/optimal, many systems perturbed < 30; the exact male threshold is uncertain, 30 is the conservative choice). Per-day EA < 30 raises a warning.
- Rate caps (cut ≤ 1.0 %/wk), minimum carbs warning (< 3 g/kg), "calories can't cover protein + minimum fat" error.
- No cut suggestions below a minimum BMI/body-fat threshold; show a plain-language reason. Disordered-eating-sensitive wording (no moralising, no streak guilt, no "cheat" language).
- Not medical advice; one-time acknowledgement in onboarding.

### 9.8 Layer 8 — Peri-workout fuelling (pre / during / post)
Carved from the day's macros, not added on top. Reference code `PeriWorkout` (Appendix C).
- **Pre (1–4 h before):** ≈ 1 g/kg carbs per hour of lead time (cap 4 g/kg), scaled by session demand (glycolytic/hard = 1.0, moderate = 0.75, light = 0.5); protein ≈ 0.3 g/kg; low fat/fibre (fat cap 15/8/3 g at ≥3 h/2 h/<1 h — practical GI heuristic). **< 1 h:** ~25 g fast carbs only. Fasted option for short, easy sessions.
- **During:** ≥ 60 min and hard (MET ≥ 8): 30–60 g/h carbs + fluids; otherwise water ± electrolytes.
- **Post:** protein ≈ 0.4 g/kg (20–50 g) within ~2 h. Carbs: if the next *glycolytic* session is < 8 h away → 1.0–1.2 g/kg/h for 4 h; if the next is resistance-only and < 8 h → ~1 g/kg in the first 1–2 h; otherwise ~0.75 g/kg as part of the normal meal (no special loading).
- **Meal composer:** given pre/post targets and the day's remaining macros, pick from the user's **meals + favourites tagged pre/post** (small knapsack, ±10% tolerance, respecting fat/fibre caps). Output 2–3 concrete options ("Banana + Up&Go ≈ 52 g C / 12 g P") and what it does to the rest of the day. If no combination fits, explain the shortfall ("not enough carbs left today — shift 40 g from dinner?").
- Handle double days (e.g. 06:30 BJJ then midday lifting): plan both windows jointly.

### 9.9 Layer 9 — Weekly check-in, auto-regulation, nudges
Runs weekly (and on demand). Inputs: trend slope over the last 14 days, adherence (days logged ≥ 5/7 and weigh-ins ≥ 3), realised vs goal rate, optional readiness (sleep/soreness/performance 1–5).
- **Insufficient data / low adherence** → hold target, say why, show what to fix.
- **Bulk guard:** realised gain > 1.5× goal for 2 weeks → reduce; < 0.5× goal with adherence ≥ 85% → increase (within caps; the TDEE-based formula already does most of this).
- **Cut guard:** plateau or performance dropping 2+ weeks while rate > goal → ease deficit; suggest a diet break after 8–12 weeks (heuristic).
- **Undereating detector (important for this user):** average intake < 90% of target on ≥ 4 of the last 7 logged days → friendly nudge with 2–3 calorie-dense adds from *your* frequent foods that close the protein/carb gap. Never shame.
- Every check-in produces a "why this changed" explanation (TDEE moved X, trend moved Y, smoothing held Z).
- Optional **event mode** (competition date + target weight): compute required rate, warn if outside safe caps.

### 9.10 Assumptions registry (put this in the app: Settings → "How it works")
| Parameter | Value | Basis | Confidence |
|---|---|---|---|
| Protein, exercising | 1.4–2.0 g/kg/d; 2.3–3.1 g/kg FFM when lean & hypocaloric | ISSN protein stand 2017 (PMC5477153); Helms 2014 | High |
| Protein per feeding | 0.25 g/kg or 20–40 g (ISSN); 0.4–0.55 g/kg (Iraki 2019) / 0.4–0.5 around training (Helms) | as listed; spaced every 3–4 h | High/Med |
| Fat | 0.5–1.5 g/kg (Iraki); 15–30% kcal (Helms); floor used: 0.6 g/kg | as listed | Med (floor is a conservative choice) |
| Carbs daily | 3–5 / 5–7 / 6–10 / 8–12 g/kg by load | ACSM/AND/DC 2016, Burke et al. (endurance-derived; resistance mapped conservatively) | High for endurance, Med for lifting/BJJ |
| Pre-exercise carbs | 1–4 g/kg, 1–4 h before; < 1 h small amount | ACSM/AND/DC 2016 | High |
| During | 30–60 g/h for ≥ 60 min hard work | ACSM; ISSN nutrient timing 2017 | High |
| Post (< 8 h to next) | 1.0–1.2 g/kg/h × 4 h (ISSN: 1.2 g/kg/h; or 0.8 g/kg/h carbs + 0.2–0.4 g/kg/h protein) | ACSM; ISSN 2017 | High |
| Bulk rate / surplus | 0.25–0.5 %BW/wk novice-intermediate; 10–20% surplus | Iraki 2019 | Med |
| Cut rate | 0.5–1.0 %BW/wk | Helms 2014 | Med |
| Energy availability | ≥ 45 optimal; < 30 perturbs many systems | IOC RED-S 2018 | High (female data; male thresholds less certain) |
| Exercise MET values | BJJ 5.3/7.5/10.3; lifting 3.5/5.0/6.0; HIIT 7.0/11.0; 4×4 8.8 (estimate) | 2024 Compendium (codes in Appendix B); ABS 7.5 judo/jujitsu | Med; individual error ±25% |
| 7700 kcal/kg | tissue energy density | standard approximation | Med |
| Fibre 14 g/1000 kcal; pre-meal fat/fibre caps; periodisation k = 0.6; load-class thresholds | engineering heuristics | — | Low — tune with data |

---

## 9A. Training-companion layer (Milestone M10)

Everything here sits **on top of** the engine in section 9. Rule of the layer: **the engine and database decide numbers; models explain and parse language; the user decides actions.**

### 9A.1 Imperfect-day mode (design for real life, never "all or nothing")
Goal: a messy day must never turn into no data and no engagement. Most trackers punish imperfection; this one must not.
- **"Good enough" log:** on Today, a single button **"Roughly on target"** records the day at ~target with a *wide* uncertainty band. **"Roughly under / over"** variants add ±300 kcal offsets. Also a **"Rough estimate"** quick-add for a whole meal (e.g. "takeaway ≈ 900 kcal").
- **Engine handling:** these days feed the Kalman filter as intake with inflated `intakeSd` (≈ 300–400 kcal) instead of being skipped or treated as exact. Truly unlogged days use the existing missing-intake path. Add engine tests proving estimates don't corrupt TDEE (simulation: mix of exact, rough and missing days; error must stay within the section 9.1 bounds ± 40 kcal).
- **Scoring:** replace daily streaks with a **weekly consistency score** = days that were logged *or* "good enough" ÷ 7, plus a separate "within ±15% of target" rate. Show weekly bars, not a fragile chain. A missed day shows as a neutral gap, never red, never "streak lost". Copy is encouraging and factual ("5 of 7 days counted — the engine is happy with that").
- **Minimum viable day:** a Today **compact mode** (weight optional, 3 big buttons) for chaotic days. Training equivalent: **"Did something"** logs a session of any size (10 min counts) so partial sessions aren't discarded.
- **Weekly check-in** never blocks on perfect data; it states what it can and can't conclude.
- Acceptance: a user can log a day in ≤ 2 taps without detailed food entry; a 3-day gap followed by a 1-tap day produces no error states and no scolding copy.

### 9A.2 AI coach chat (grounded, tool-using)
- **Where:** `/coach` screen (chat) + "Ask about this" buttons on charts and the check-in card.
- **Pattern:** server-side Anthropic call with **function/tool calling**. The model has *read-only* tools that wrap existing services; it has no database access and no ability to change targets or log data.
  - `get_today_status()` · `get_targets(date)` · `get_week_plan()` · `get_trend(range)` · `get_expenditure(range)` · `get_weekly_checkin(weekId)` · `get_training(range)` · `get_adherence(range)` · `get_peri_workout(sessionId)` · `search_my_foods(q)` · `get_assumptions()`
- **System-prompt rules:** (1) every number in an answer must come from a tool result — never from memory or arithmetic; (2) explain *why* using the engine's own reason codes; (3) no medical advice, no diagnosis, no extreme-diet suggestions; defer to the guardrails in 9.7; (4) be brief and concrete; (5) when data is thin, say "calibrating" and what's missing; (6) suggest actions as options, the user confirms; (7) neutral, non-shaming tone.
- **Examples it must handle:** "why did my target drop 80 kcal?" · "what should I eat before tomorrow's 6:30 BJJ and how does it fit my day?" · "am I gaining too fast?" · "swap tomorrow's lifting to Thursday — what changes?" (read-only what-if via the planner, shown as a preview).
- **Models:** stronger model for the coach; model IDs in `.env`. Streamed responses; conversation stored in `coach_threads`/`coach_messages` with tool-call traces for debugging.
- **Safety/eval:** an automated test extracts every number in each answer and asserts it appears in that turn's tool results (±rounding). Fail CI on ungrounded numbers. Golden prompts (≥ 30) for the examples above, with expected tool calls.
- **Privacy:** only the minimum needed tool data is sent to the API; no raw food-history dump; user can disable the coach entirely.

### 9A.3 Weekly written review
- **Scheduled** (Sunday evening, user-timezone) job: the engine produces a structured `WeeklyReview` JSON (trend slope, realised vs goal rate, adherence, avg protein per feeding, undereating flags, training load by day-type, TDEE change + reason codes, proposed target for next week).
- A small model turns the JSON into a ≤ 150-word note: *what happened · what it means · 1–2 suggested actions*, with a deterministic **template fallback** if the API is down. Numbers are interpolated from the JSON (not generated). Saved to `weekly_reviews`; shown in-app and optionally pushed as a notification; optional export to the Obsidian vault as a markdown note.

### 9A.4 Voice/text workout logging
Reuse the capture pipeline (section 6): *"rolled five rounds, hard, about an hour with drilling, then 40 minutes heavy lifting"* → parsed items `{type, intensity, minutes, rpe?, notes}` → review cards with quick edit → energy via `ExerciseCalculator` (Appendix B). Unknown activities fall back to a custom MET entry the user can save as a preset. Same alias-learning idea: "usual BJJ class" → saved preset.

### 9A.5 Training sync
- **Todoist (training schedule):** read-only integration using the user's Todoist API token (env/Settings). Map the configured training project/section/labels to the weekly `training_template` (session type, start time, duration, intensity via label or title convention). Pull nightly and on demand; **manual overrides win** and are never overwritten. Show a diff before applying changes. Never write to Todoist unless the user later enables "send shopping list" (section 5.6).
- **Hevy (lifting):** CSV import mapping sets/reps/weight → session volume, duration, and top-set RPE if present; use for session intensity and for the insights in 9A.6. Inspect a real export in `/samples` before coding the parser.
- **Polar H10 (heart rate):** Web Bluetooth (Chrome on Android; not supported on iOS Safari) using the standard Heart Rate service. A "Start session" screen records HR + timestamps; on finish compute duration, avg/max HR, **time in zones** (needs user HRmax, set manually) and a training-load score. **Do not** invent a calorie formula: v1 uses HR to suggest an *intensity class* (and RPE) for the MET table, and stores raw HR for later calibration. Keep recording resilient (reconnect, screen-wake lock).
- **Polar after-the-session sync (optional, later):** Polar AccessLink API (free self-serve OAuth client at admin.polaraccesslink.com, user must be registered after consent) can pull exercise summaries, HR samples and zones once the strap data reaches Polar Flow. It has no continuous 24/7 stream. Live Web Bluetooth above remains the primary H10 route.
- **COROS (watch + app):** the official COROS Partner API is for established platforms with a registered company, so it is **not** realistic for a single-user app. Practical routes, in order: (1) **FIT/bulk-export import** (COROS support offers a bulk export of your data) in M9 history import; (2) a self-hosted bridge via a third-party aggregator or Strava link if you accept the extra hop and terms; (3) investigate COROS's official MCP server for the *coach* only (it exposes workouts, sleep, HRV, resting HR; early reports of outages), never as a dependency. Known limit: COROS's API exposes sleep start/end, avg/resting HR and HRV, **not sleep stages or a sleep score**. Use sleep start/end and HRV/resting HR only for the check-in and insights (9A.6).
- **Google Health Connect (Samsung Health / Google):** Health Connect stores data **on the phone** and has **no web/server API**, and the Google Fit REST API is being shut down, so a PWA **cannot** read it. Needs a small native Android wrapper (e.g. Capacitor Health Connect plugin) or a companion app that pushes steps/sleep/HR to the server. Treat as P2; do it only if COROS import and Polar H10 prove insufficient. Verify Samsung Health to Health Connect sharing on the actual phone before designing around it.
- All integrations are optional, behind Settings toggles, and fail soft. Data from wearables feeds **intensity class, recovery and insights only**; it never adds calories to the daily budget.

### 9A.6 Morning check-in & personal insights
- **10-second check-in** (optional, skippable): sleep quality 1–5, soreness 1–5, mood/energy 1–5, optional notes. Stored in `readiness_logs`.
- **Insights engine (deterministic):** after ≥ 8 weeks, compute simple, honest correlations (e.g. next-day session quality vs prior-day carbs g/kg, sleep vs soreness, weekly volume vs trend). Require a minimum sample (n ≥ 8 pairs), show effect size + sample size + a **"correlation, not proof"** label, never causal language, never alarmist. The coach may explain them but cannot invent new ones.
- **Readiness hooks:** if soreness/sleep are poor for 3+ days *and* the plan has a hard day, suggest options (lighter session, extra carbs pre, move the session). User chooses.

### 9A.7 Event mode (competition / target weight)
Inputs: event date, target weight (or weight class), current trend. Engine computes the **required %/wk**, checks against safe caps (9.3/9.7), and returns one of: *on track · needs a slower/faster rate · not achievable safely by that date*, with alternatives (move date, different target). Supports a **bulk-then-cut timeline** (phase boundaries, target weights at each). Cuts for weigh-ins must respect the safety floor — **no acute dehydration/"weight-cut" protocols** in v1; link out to professional guidance in the UI.

### 9A.8 Data model additions
`coach_threads` · `coach_messages(thread_id, role, content, tool_trace jsonb)` · `weekly_reviews(week_start, review_json, note_text, generated_by[model|template])` · `readiness_logs(local_date, sleep, soreness, energy, notes)` · `integrations(type[todoist|hevy|polar], config jsonb, last_sync)` · `hr_sessions(workout_log_id, samples blob/jsonb, hr_max_used, zones jsonb, load_score)` · `events(date, target_kg, kind, phase_plan jsonb)` · `daily_logs.completeness enum[exact|rough|missing]` + `intake_sd`.

### 9A.9 Acceptance for M10
(1) rough-day simulation within error bounds; (2) coach answers to all golden prompts use only tool-supplied numbers (CI check); (3) weekly review renders from template when the API is disabled; (4) Todoist sync shows a diff and never overwrites manual edits; (5) a Polar H10 session survives a brief disconnect; (6) insights never display with n < 8 pairs; (7) event mode returns "not achievable safely" for a 2%/wk request.

---

### 9B. Sleep & recovery loop (Milestone M11, after M10)
Goal: one private place where sleep, training and food inform each other, in both directions: **sleep -> today's plan** and **food/training -> tonight's sleep**. Single user, own server, no sharing.

**9B.1 Inputs (all optional, fail soft)**
- Sleep start/end, resting HR, HRV from: manual check-in (always available), COROS export import (9A.5), Health Connect companion (P2), Polar sessions for training HR. COROS exposes **no sleep stages or score**, so do not build on them. Everything below works from **duration, timing, resting HR and HRV**.
- Extra daily fields that actually drive sleep and are cheap to log: caffeine (mg and time of last), alcohol (standard drinks and time), last-meal time, session start time and intensity (already in workouts), supplement timing (already logged).
- Shift-work aware: the owner works afternoons/evenings, so sleep windows are late. Never assume a 10pm bedtime. Compare work days vs off days separately.

**9B.2 Derived metrics (computed by the engine, pure PHP, tested)**
- Sleep duration vs the user's own target (user-set, no default prescribed), 7- and 28-day averages, **sleep debt** vs that target.
- **Timing consistency:** SD of sleep midpoint over 7 days.
- **HRV and resting HR vs the user's own 28-day baseline** (z-score style, never against population norms). One night means little; show trends.
- Optional **FUEL sleep index**: a transparent composite of the four items above with editable weights, always labelled "our own simple index, not a clinical score". Every component is shown beside it. Do not imitate any vendor's score.

**9B.3 Sleep -> plan (deterministic rules, options only, user confirms)**
- Poor sleep (below own target or HRV well under baseline) **and** a hard session planned -> suggest: lighter session, move it, or extra carbs before. Offer, never auto-change.
- Poor sleep alone **never** changes calories or macros and never triggers a cut. Protein stays constant (9.4).
- Repeated short sleep for 2+ weeks -> a gentle, non-alarming prompt; persistent problems -> "worth raising with your GP" (no diagnosis, no apnoea or medical claims).

**9B.4 Food/training -> sleep (n-of-1 analysis)**
- Pre-declared hypothesis list (max about 8), e.g. late caffeine, alcohol, last-meal time, late hard session (BJJ finishing after a set time), prior-day carbs g/kg, total protein, vitamin D supplement time, screen of work-day vs off-day. No fishing across everything.
- Method: lagged comparison (night after X vs night after not-X), stratified by work day / off day; rank correlation or median difference with a **bootstrap confidence interval**; report effect size, CI and n; apply false-discovery control across the list.
- **Minimum 21 nights per comparison** (stricter than 9A.6's 8 pairs, because sleep is noisy). Label every result **"association, not proof"**; no causal wording.
- **Self-experiments:** user starts a 14-day trial (e.g. no caffeine after 2pm); the app shows before/during with the same caveats. The coach may explain results but cannot invent new ones (9A.2).

**9B.5 Screens**
Sleep timeline (last 14 nights, work/off shading), 7/28-day trends with the personal baseline band, "What moves your sleep" (only items passing 9B.4), experiment tracker, and sleep/HRV chips on Today and in the weekly review (9A.3). Quick view cards (Today) can show sleep duration, HRV and resting HR.

**9B.6 Data model additions**
`sleep_nights(local_date, start_at, end_at, duration_min, source[manual|coros_import|health_connect], resting_hr, hrv_ms, hrv_method, quality_1_5 null)` · `daily_logs += caffeine_mg, caffeine_last_at, alcohol_std, alcohol_last_at, last_meal_at` · `experiments(name, variable, start, end, rule jsonb, outcome jsonb)` · `insight_runs(hypothesis, n, effect, ci_low, ci_high, shown bool, run_at)`.

**9B.7 Acceptance for M11**
(1) no sleep-vs-lifestyle result shown with n < 21 nights; (2) synthetic data where sleep depends only on work/off day -> the engine reports **no** diet effect; (3) a planted effect (late BJJ delays sleep by about 40 min) is recovered inside its CI; (4) sleep alone never changes calories or macros; (5) COROS export parser passes on a real sample in `/samples`; (6) false-discovery control test passes; (7) all sleep screens work with only manual check-ins.

---

## 10. Testing & quality

- **Engine:** port Appendix tests to Pest. Keep the simulation (`simulate.php`) as a CI job; fail if mean abs error (weeks 3–6) > 150 kcal or late-phase error isn't ≥ 2× better than the naïve formula. Invariants: weekly kcal conserved (±30 kcal), carbs/day within class range or flagged, fat ≥ floor, EA floor enforced, no NaN/negative macros.
- **Property tests** on `MacroPlanner` with random weights/phases/weeks.
- **Capture eval** (6.9) in CI. **Playwright** smoke: onboard → search → log → scan (mocked) → capture (mocked LLM) → weigh-in → check-in.
- **Perf:** search p95 < 150 ms with 200k food rows; Lighthouse PWA ≥ 90; TTI < 2 s on mid-range Android.
- **Data safety:** idempotent writes, optimistic UI with rollback, export/import round-trip test.

---

## 7A. Progress photos and micronutrients (additions)

- **Progress photos** (M7): Front/Side/Back by date, side-by-side compare, delete. Stored privately on the owner's own server (never third-party); prototype keeps them on-device in IndexedDB. Strip EXIF location on upload.
- **Micronutrient overview** (M3 data, M7 view): bars vs targets for an adult male 19-50, lowest-gap-first, sodium as a limit. Targets must come from NHMRC Nutrient Reference Values; the prototype has only iron (8 mg) and zinc (14 mg) verified, the rest are US stand-ins tagged `src:'US'` and must be replaced before release. Always show a data-coverage banner: packaged foods often lack micronutrient data so totals under-count.

### 7B. Health tracking (additions)
- **Supplements:** saved stack (name, dose, unit, optional nutrient contribution); one tap logs, tap again undoes; totals feed micronutrient view. Doses are user-entered, never recommended.
- **Blood tests:** results with the lab's own reference range typed from the report; flags low/high against that range only; general food ideas for low results; always defer to GP. Creatine can raise creatinine without kidney change: show that note.
- **Sun & vitamin D:** UV index (live from ARPANSA/BoM for Melbourne in the real app) x minutes x skin area x skin tone. Rules of thumb: 1 UVI-hour = 0.9 SED; 1 MED = 2 SED; whole body at 1 MED about 20,000 IU; 1/3 MED on 15% of body about 1,000 IU. Show a range, label as a rough estimate, and prefer a blood test.
- **Profile:** height, DOB, sex, job activity, shift pattern, lifting years collected in onboarding; feeds the day-one prior only.

---

## 11. Milestones (feed one at a time)

| # | Scope | Acceptance |
|---|---|---|
| **M0** | Repo, Docker, Coolify deploy, Fortify + passkeys, PWA shell, CI, backup + restore script | Install on phone over HTTPS, passkey login works, restore tested |
| **M1** | Port engine (Appendix A–C) into `app/Domain/Nutrition`, Pest tests, simulation CI job, onboarding + profile + goals | All engine tests green; onboarding produces a prior TDEE and first targets |
| **M2** | AFCD + OFF-AU importers, hybrid search, synonyms, query quantity parsing | Search p95 < 150 ms; "capsicum", "mince", "weetbix 2" behave |
| **M3** | Today screen, fast logging (recents/frequents, copy day, serving stepper, quick-add), design system | Repeat a food in ≤ 2 taps; screenshots reviewed |
| **M4** | Barcode scan (+WASM fallback), OFF live lookup/cache, user foods, label photo (optional) | Scan 10 real AU products; unknown → create flow works |
| **M5** | Custom meals/recipes, meal-prep mode, tags | Recipe macros per portion verified by hand |
| **M5b** | **Recipe multi-select → shopping list**, pantry, aisle grouping, check-off, offline, Todoist export | All 8 shopping-list acceptance tests pass; usable one-handed in a supermarket |
| **M6** | **AI Meal Capture** (parse, retrieve, review/swap UI, aliases, voice, fallback) + eval harness | Golden-set targets in 6.9; swap sheet < 100 ms; offline fallback works |
| **M7** | Weight logging, Kalman trend/expenditure charts, weekly check-in, adherence + undereating nudges | Charts match engine; check-in explains changes |
| **M8** | Exercise logging, training template, day-type planner, replan, peri-workout card + meal composer | Planner invariants hold; double-day case correct |
| **M9** | Offline outbox, PWA polish, history import (MacroFactor/Cronometer/Hevy), exports, insights | Airplane-mode logging syncs without duplicates |
| **M10** | **Training companion:** imperfect-day mode, AI coach (grounded), weekly review, voice workout log, Todoist/Hevy/Polar sync, check-in + insights, event mode | Section 9A.9 acceptance list passes |
| **M11** | **Sleep & recovery loop** (9B): sleep/HRV/resting HR import, personal baselines, sleep-informed plan options, n-of-1 insights, self-experiments | Section 9B.7 acceptance list passes |

Kick-off prompts: *"Do M0 only…"* → review → *"Do M1…"* etc. For M6, first build `capture:eval` and the golden set, **then** the pipeline.

---

## 12. Seed profile (editable defaults for the owner)
Melbourne TZ · height ≈ 180 cm · bodyweight from first weigh-in (≈ 74 kg) · phase **lean bulk**, protein override **2.2 g/kg** (≈ 165 g) · current manual macros 165 P / 85 F / 380–480 C (sanity check: the planner reproduces a similar range, see worked example below) · occupation: high-NEAT warehouse/linehaul (afternoon shifts) · training template: BJJ Mon 06:30, Tue 11:30, Thu 11:30, Sat 10:00; lifting Mon/Wed/Fri midday; Norwegian 4×4 on Fridays · tendency to undereat → enable nudges · import history from MacroFactor/Cronometer when exports are available · age: ask in onboarding (do not assume).

### Worked example (output of the reference planner; 74 kg, 14% BF, 3,150 kcal avg)
```
Day  Class     kcal   P    F    C   C g/kg  Training(net kcal)
mon  high      3372  163   88  482   6.5     789  (BJJ + lifting)
tue  moderate  3060  163   88  404   5.5     481
wed  moderate  3060  163   88  404   5.5     308
thu  moderate  3060  163   88  404   5.5     481
fri  high      3372  163   88  482   6.5     693  (lifting + 4x4)
sat  high      3372  163   88  482   6.5     722
sun  rest      2748  163   88  326   4.4     0
Weekly average 3,149 kcal · 427 g carbs · energy availability 40–43 kcal/kg FFM every day
```
Peri-workout example (Mon BJJ 60 min, meal 2 h before, lifting 5 h later): pre ≈ 148 g carbs / 22 g protein / fat ≤ 8 g; post ≈ 30 g protein + ~74 g carbs (next session is resistance-only).

---

## 13. Sources
ISSN protein & exercise (2017) pmc.ncbi.nlm.nih.gov/articles/PMC5477153 · ISSN nutrient timing (2017) jissn.biomedcentral.com/articles/10.1186/s12970-017-0189-4 · ACSM/AND/DC Nutrition & Athletic Performance (2016) + Burke carbohydrate ranges (as summarised in GSSI/Nutrients tables) · IOC RED-S consensus (2018) · Helms et al. 2014 JISSN 11:20 · Iraki et al. 2019 Sports 7:154 (PMC6680710) · 2024 Compendium of Physical Activities (pacompendium.com) · ABS MET table · FSANZ AFCD Release 3 (foodstandards.gov.au/about-afcd) · Open Food Facts API docs (openfoodfacts.github.io) · MacroFactor public algorithm articles (for concepts only; no code or assets copied) · Apple App Review Guidelines 4.2, 4.8, 5.1.1(v) (developer.apple.com) · Google Play Console Help: app testing requirements for new personal accounts · OAIC guidance on health information / sensitive information (oaic.gov.au).

---

## 14. Going public (App Store, Play Store, other users) — PARKED (not building; reference only)

**Honest recommendation:** run it as a **PWA for yourself now** (free, no review, fully capable). Only go to the stores if you want other people to use it. If you do, plan for **Capacitor** (wraps this web app) plus real native features, because a plain wrapper risks rejection.

### 14.1 Distribution options
| Option | Store? | Cost | Notes |
|---|---|---|---|
| PWA (home-screen install) | No | $0 | Best for personal use; no review. iOS push needs the app installed to the home screen. |
| **Capacitor wrapper + native plugins** | App Store + Play | Apple $99 USD/yr, Google $25 once | Reuses the Laravel/React code. Must clear Apple guideline 4.2 (see below). |
| Android TWA | Play only | $25 once | Thin Play listing of the PWA. |
| Expo/React Native rewrite | Both | same fees | Only if the wrapper approach is rejected or you want fully native feel. |

### 14.2 Store requirements to design for from day one
- **Apple 4.2 (minimum functionality):** the app must be more than a repackaged website. Add real native capability: native barcode scanning (ML Kit/AVFoundation plugin), **HealthKit / Health Connect** read/write (weight, workouts, steps), local notifications, haptics, Face ID/biometric app lock, home-screen widget (P2), and graceful **offline** behaviour (reviewers test airplane mode). Submit with reviewer notes explaining the native features.
- **In-app account deletion (5.1.1(v)):** required for any app with account creation; must actually delete, not just deactivate; must work without leaving the app. If Sign in with Apple is offered, revoke its tokens on deletion.
- **Sign in with Apple (4.8):** if you offer Google/other third-party login as the primary account route, you must also offer an equivalent privacy-preserving option (Sign in with Apple usually). Email+passkey only avoids the issue.
- **Privacy labels / privacy manifest / privacy policy URL** and accurate permission strings (camera, microphone for voice, notifications, health).
- **Google Play:** $25 one-time. A *personal* account created after 13 Nov 2023 must run a **closed test with ≥12 opted-in testers for 14 continuous days** before production access; an *organisation* account is exempt but needs a D-U-N-S number. Plan ~3 weeks for first launch.
- **Subscriptions:** if you charge, digital subscriptions in the iOS app normally must use Apple In-App Purchase (guideline 3.1.1); check current rules and fees before pricing.
- **Health-adjacent safeguards:** calorie apps get scrutiny for eating-disorder risk — keep the guardrails in 9.7, no extreme targets, no body-shaming copy, clear "not medical advice".

### 14.3 Legal & data (Australia) — get proper advice before launch
- Health information is **sensitive information** under the Privacy Act 1988; nutrition and health goals count as health information in common legal guidance. Collection generally needs **informed, specific, voluntary consent**, a clear privacy policy, and a way to withdraw. Law-firm guidance says businesses handling health/fitness data can be covered by the Act even under the $3M turnover threshold. Not legal advice — consult a lawyer.
- Data licences: Open Food Facts is ODbL (attribution + share-alike on the database); AFCD requires attribution to FSANZ; keep an "About / Data sources" screen.
- Don't copy MacroFactor/Cronometer branding, copy or UI assets; check the trademark for the final app name.

### 14.4 Multi-user hardening (needed before anyone else signs up)
- **Tenant isolation:** every query scoped by `user_id` via a global Eloquent scope **plus Postgres Row-Level Security** as a second wall; tests that try cross-user reads on every endpoint.
- Rate limits per user/IP; **per-user AI quotas** and a global spend cap; abuse reporting; email verification; audit log of sign-ins; device/session list with revoke.
- Data export (JSON/CSV) and full deletion (cascade + backups retention policy) from Settings; GDPR-style rights are good practice even if not legally required.
- Observability: Sentry, structured logs, uptime checks, DB slow-query log; staged deploys; migrations tested on a copy of prod data.
- Managed Postgres (or self-hosted with WAL archiving/PITR via pgBackRest or WAL-G + PgBouncer) once real users exist.

### 14.5 Auth & database recommendations
- **Auth:** Laravel Fortify with **passkeys (WebAuthn) as the primary method**, plus email magic-link/OTP or password fallback. Passwords hashed with **Argon2id**, reject breached passwords, TOTP 2FA with recovery codes, secure+HttpOnly+SameSite cookies, CSRF, strict CSP, login throttling, session rotation on login, short-lived tokens for the native shell (store in Keychain/Keystore via secure-storage plugin, never localStorage). Don't hand-roll crypto. If you outgrow it (many social logins, enterprise SSO), move to a managed provider (e.g. Clerk/Auth0/WorkOS) — decide at public launch, not now.
- **Database:** **PostgreSQL** (keep it). Reasons: full-text + trigram search for foods, JSONB for plans, RLS for tenant isolation, `pgvector` for semantic food matching, mature backups. Split a read-mostly `reference` schema (AFCD/OFF foods) from per-user data so food-database refreshes never touch user data. Index `(user_id, local_date)` on logs. SQLite is fine for strictly single-user, but not for a public app.

---

# APPENDICES — tested reference code (PHP 8.3)
Copy into `app/Domain/Nutrition/` (adjust namespace from `Engine` to your app namespace) and `tests/Engine/`. The tests print the worked example above.

## Appendix A — Adaptive expenditure + weekly target + simulation test

### src/ExpenditureFilter.php
```php
<?php
declare(strict_types=1);

namespace Engine;

/**
 * Joint Kalman filter over [trend weight (kg), expenditure (kcal/day)].
 *
 *   W[t+1] = W[t] + (intake[t] - E[t]) / kcalPerKg      (energy balance)
 *   E[t+1] = E[t] + noise                               (slow random walk)
 *   scale[t] = W[t] + noise                             (noisy observation)
 *
 * Missing weigh-in  -> skip the update (uncertainty grows).
 * Missing intake    -> assume intake == E and add large uncertainty.
 * Water spikes      -> outliers get their measurement noise inflated.
 */
final class ExpenditureFilter
{
    public function __construct(
        private float $kcalPerKg = 7700.0,
        private float $scaleSd = 0.7,          // kg, day-to-day scale noise
        private float $weightProcessSd = 0.05, // kg/day unmodelled drift
        private float $expenditureProcessSd = 20.0, // kcal/day random walk
        private float $intakeSd = 150.0,       // kcal, logging error per day
        private float $missingIntakeSd = 700.0,
        private float $outlierZ = 2.5,
    ) {}

    /**
     * @param array<int,array{date:string,weight:?float,intake:?float}> $days chronological, one row per day
     * @param float $priorTdee   starting estimate (e.g. Mifflin-St Jeor * activity)
     * @param float $priorTdeeSd how unsure we are of that prior
     * @return array<int,array{date:string,trend:float,tdee:float,tdeeSd:float,scale:?float}>
     */
    public function run(array $days, float $startWeight, float $priorTdee, float $priorTdeeSd = 350.0): array
    {
        $k = $this->kcalPerKg;
        $w = $startWeight;
        $e = $priorTdee;
        $p11 = 1.0;
        $p12 = 0.0;
        $p22 = $priorTdeeSd ** 2;
        $out = [];
        $prevIntake = null;

        foreach ($days as $i => $d) {
            if ($i > 0) {
                // PREDICT: yesterday's intake produces this morning's weight.
                if ($prevIntake !== null) {
                    $w += ($prevIntake - $e) / $k;
                    $n11 = $p11 - 2 * $p12 / $k + $p22 / ($k * $k);
                    $n12 = $p12 - $p22 / $k;
                    $p11 = $n11 + $this->weightProcessSd ** 2 + ($this->intakeSd / $k) ** 2;
                    $p12 = $n12;
                } else {
                    $p11 += $this->weightProcessSd ** 2 + ($this->missingIntakeSd / $k) ** 2;
                }
                $p22 += $this->expenditureProcessSd ** 2;
            }

            // UPDATE with the scale reading, if any.
            if ($d['weight'] !== null) {
                $r = $this->scaleSd ** 2;
                $v = $d['weight'] - $w;
                $s = $p11 + $r;
                $z = abs($v) / sqrt($s);
                if ($z > $this->outlierZ) {
                    $r *= ($z / $this->outlierZ) ** 2; // down-weight spikes
                    $s = $p11 + $r;
                }
                $k1 = $p11 / $s;
                $k2 = $p12 / $s;
                $w += $k1 * $v;
                $e += $k2 * $v;
                $p22 -= $k2 * $p12;
                $p11 -= $k1 * $p11;
                $p12 -= $k1 * $p12;
            }

            $out[] = [
                'date' => $d['date'],
                'trend' => $w,
                'tdee' => $e,
                'tdeeSd' => sqrt(max($p22, 0.0)),
                'scale' => $d['weight'],
            ];
            $prevIntake = $d['intake'];
        }

        return $out;
    }
}
```

### src/TargetCalculator.php
```php
<?php
declare(strict_types=1);

namespace Engine;

final class TargetCalculator
{
    /** Mifflin-St Jeor BMR * activity multiplier: only used as the day-one prior. */
    public static function priorTdee(float $kg, float $cm, int $age, string $sex, float $activity = 1.4): float
    {
        $bmr = 10 * $kg + 6.25 * $cm - 5 * $age + ($sex === 'male' ? 5 : -161);
        return $bmr * $activity;
    }

    /**
     * Weekly check-in. $ratePctPerWeek: -0.5 = lose 0.5% bodyweight/week, +0.25 = gain.
     * Moves only part of the way to the ideal each week and caps the step, so one noisy
     * week can't whiplash the user.
     */
    public static function weeklyTarget(
        float $tdee,
        float $trendKg,
        float $ratePctPerWeek,
        ?float $previousTarget = null,
        float $kcalPerKg = 7700.0,
        float $smoothing = 0.6,
        float $maxStep = 150.0,
        float $floor = 1400.0,
    ): float {
        $dailyBalance = ($ratePctPerWeek / 100) * $trendKg * $kcalPerKg / 7;
        $ideal = max($floor, $tdee + $dailyBalance);
        if ($previousTarget === null) {
            return round($ideal);
        }
        $step = ($ideal - $previousTarget) * $smoothing;
        $step = max(-$maxStep, min($maxStep, $step));
        return round($previousTarget + $step);
    }
}
```

### tests/simulate.php (40 simulated people; run: php tests/simulate.php)
```php
<?php
declare(strict_types=1);
require __DIR__ . '/../src/ExpenditureFilter.php';
require __DIR__ . '/../src/TargetCalculator.php';
use Engine\ExpenditureFilter;
use Engine\TargetCalculator;

function gauss(): float { return sqrt(-2 * log(max(mt_rand() / mt_getrandmax(), 1e-12))) * cos(2 * M_PI * mt_rand() / mt_getrandmax()); }

/** Simulate a person with KNOWN true TDEE, then see if the filter recovers it. */
function simulate(int $seed, int $n = 90): array {
    mt_srand($seed);
    $kg = 90.0; $trueW = $kg; $days = []; $truth = [];
    for ($i = 0; $i < $n; $i++) {
        $tdee = 2800 + 22 * ($trueW - 90) + ($i >= 45 ? -180 : 0); // adapts to weight, plus activity drop at day 45
        $eaten = 2300 + 250 * gauss();                              // what they actually ate
        $logged = $eaten * (1 + 0.04 * gauss());                    // logging error
        $spike = (mt_rand() / mt_getrandmax() < 0.07) ? 1.5 + abs(gauss()) : 0; // salty-meal water
        $scale = $trueW + 0.6 * gauss() + $spike;
        $days[] = [
            'date' => date('Y-m-d', strtotime("2026-01-01 +$i day")),
            'weight' => (mt_rand() / mt_getrandmax() < 0.15) ? null : $scale, // 15% missed weigh-ins
            'intake' => (mt_rand() / mt_getrandmax() < 0.10) ? null : $logged, // 10% missed logs
            '_eaten' => $eaten,
        ];
        $truth[] = ['tdee' => $tdee, 'w' => $trueW];
        $trueW += ($eaten - $tdee) / 7700;
    }
    return [$days, $truth];
}

$f = new ExpenditureFilter();
$prior = TargetCalculator::priorTdee(90, 180, 30, 'male', 1.4); // deliberately a bit off
$errs = ['wk1-2' => [], 'wk3-6' => [], 'post-step 3wk' => [], 'late' => []];
$naiveErr = [];
$seeds = range(1, 40);
foreach ($seeds as $s) {
    [$days, $truth] = simulate($s);
    $res = $f->run($days, $days[0]['weight'] ?? 90.0, $prior);
    foreach ($res as $i => $r) {
        $err = abs($r['tdee'] - $truth[$i]['tdee']);
        if ($i < 14) $errs['wk1-2'][] = $err;
        elseif ($i < 42) $errs['wk3-6'][] = $err;
        elseif ($i < 66) $errs['post-step 3wk'][] = $err;
        else $errs['late'][] = $err;
    }
    // Naive baseline: avg logged intake - 14d scale change * 7700 / 14
    for ($i = 28; $i < 90; $i++) {
        $win = array_slice($days, $i - 14, 15);
        $ws = array_values(array_filter(array_column($win, 'weight'), fn($x) => $x !== null));
        $is = array_values(array_filter(array_column($win, 'intake'), fn($x) => $x !== null));
        if (count($ws) < 4 || count($is) < 8) continue;
        $naive = array_sum($is) / count($is) - (end($ws) - $ws[0]) * 7700 / 14;
        $naiveErr[] = abs($naive - $truth[$i]['tdee']);
    }
}
$avg = fn($a) => count($a) ? array_sum($a) / count($a) : NAN;
printf("Prior (formula) TDEE: %.0f kcal; true starts at 2800\n\n", $prior);
printf("%-16s %s\n", 'Phase', 'mean |error| kcal (40 simulated people)');
foreach ($errs as $k => $v) printf("%-16s %6.0f\n", $k, $avg($v));
printf("%-16s %6.0f   <- naive 14-day formula\n", 'naive (all)', $avg($naiveErr));

$ok = $avg($errs["wk3-6"]) < 150 && $avg($errs["late"]) < 160 && $avg($errs["late"]) * 2 < $avg($naiveErr);
echo $ok ? "\nPASS\n" : "\nFAIL\n";
exit($ok ? 0 : 1);
```

## Appendix B — Exercise energy (2024 Compendium METs)

### src/ExerciseCalculator.php
```php
<?php
declare(strict_types=1);

namespace Engine;

/**
 * MET-based exercise energy using the 2024 Compendium of Physical Activities.
 * NET kcal = (MET - 1) * kg * hours  (the "-1" removes resting metabolism, which the
 * TDEE already counts - avoids double counting). Treat results as +/-25%.
 */
final class ExerciseCalculator
{
    /** type => [label, intensities => [key => [met, source, glycolytic]]] */
    public const CATALOGUE = [
        'bjj' => ['label' => 'Brazilian jiu-jitsu', 'intensities' => [
            'light'    => ['met' => 5.3,  'src' => 'Compendium 15425 martial arts, slower pace/novice (drilling, technique)', 'glycolytic' => false],
            'standard' => ['met' => 7.5,  'src' => 'ABS 1721 Judo/Jujitsu (AU Dept of Health coding) - class with some live rolling', 'glycolytic' => true],
            'hard'     => ['met' => 10.3, 'src' => 'Compendium 15430 martial arts, moderate pace (hard sparring/comp)', 'glycolytic' => true],
        ]],
        'lifting' => ['label' => 'Weight training', 'intensities' => [
            'light'    => ['met' => 3.5, 'src' => 'Compendium 02054 multiple exercises 8-15 reps', 'glycolytic' => false],
            'standard' => ['met' => 5.0, 'src' => 'Compendium 02052 squats/deadlift slow or explosive', 'glycolytic' => false],
            'heavy'    => ['met' => 6.0, 'src' => 'Compendium 02050 resistance, vigorous effort', 'glycolytic' => false],
        ]],
        'hiit' => ['label' => 'HIIT', 'intensities' => [
            'moderate' => ['met' => 7.0,  'src' => 'Compendium 02210 HIIT moderate effort', 'glycolytic' => true],
            'vigorous' => ['met' => 11.0, 'src' => 'Compendium 02214 HIIT burpees/Tabata vigorous', 'glycolytic' => true],
        ]],
        'norwegian_4x4' => ['label' => 'Norwegian 4x4', 'intensities' => [
            'standard' => ['met' => 8.8, 'src' => 'Compendium 01305 cycling HIIT (closest code - ESTIMATE)', 'glycolytic' => true],
        ]],
    ];

    /** @return array{met:float,netKcal:float,low:float,high:float,glycolytic:bool,src:string} */
    public static function session(string $type, string $intensity, int $minutes, float $kg, ?float $customMet = null): array
    {
        $def = self::CATALOGUE[$type]['intensities'][$intensity] ?? null;
        if ($customMet === null && $def === null) {
            throw new \InvalidArgumentException("Unknown activity $type/$intensity");
        }
        $met = $customMet ?? $def['met'];
        $net = max(0.0, ($met - 1.0) * $kg * $minutes / 60.0);
        return [
            'met' => $met,
            'netKcal' => round($net),
            'low' => round($net * 0.75),
            'high' => round($net * 1.25),
            'glycolytic' => $def['glycolytic'] ?? ($met >= 6.0),
            'src' => $def['src'] ?? 'custom MET',
        ];
    }
}
```

## Appendix C — Macro planner, peri-workout, tests

### src/MacroPlanner.php
```php
<?php
declare(strict_types=1);

namespace Engine;

/**
 * Turns a weekly calorie target into a 7-day macro plan.
 *
 * Priority order: safety floor -> protein -> fat floor/target -> carbs (remainder),
 * then carbs are PERIODISED across days inside evidence-based g/kg ranges while the
 * weekly calorie total is conserved. Exercise calories are NOT added to the budget
 * (the adaptive TDEE already contains typical training) - they only shape distribution.
 */
final class MacroPlanner
{
    /** Burke 2011 / ACSM-AND-DC 2016 daily carbohydrate ranges, g/kg/day. */
    private const CARB_RANGE = [
        'rest'      => [3.0, 5.0],
        'light'     => [3.0, 5.0],
        'moderate'  => [5.0, 7.0],
        'high'      => [6.0, 10.0],
        'very_high' => [8.0, 12.0],
    ];
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param array{
     *   weightKg: float, sex?: string, bodyFatPct?: ?float, phase: string, kcalTarget: float,
     *   week: array<string,array<int,array{type:string,intensity:string,minutes:int}>>,
     *   proteinGPerKg?: ?float, carbMode?: string, carbCapG?: ?float, periodisation?: float
     * } $p
     */
    public static function plan(array $p): array
    {
        $kg = (float) $p['weightKg'];
        $phase = $p['phase'];
        $bf = $p['bodyFatPct'] ?? null;
        $assumedBf = $bf === null;
        $ffm = $kg * (1 - ($bf ?? 18.0) / 100);
        $warnings = [];
        if ($assumedBf) {
            $warnings[] = ['level' => 'info', 'code' => 'bf_assumed', 'msg' => 'Body fat not set: assuming 18% for fat-free mass. Add a measurement for better protein and energy-availability maths.'];
        }

        // 1. Per-day training load
        $days = [];
        $netByDay = [];
        foreach (self::DAYS as $d) {
            $sessions = [];
            $eff = 0.0;
            $net = 0.0;
            foreach ($p['week'][$d] ?? [] as $s) {
                $r = ExerciseCalculator::session($s['type'], $s['intensity'], $s['minutes'], $kg);
                $w = $r['met'] >= 6.0 ? 1.0 : ($r['met'] >= 4.0 ? 0.5 : 0.25);
                $eff += $s['minutes'] * $w;
                $net += $r['netKcal'];
                $sessions[] = $s + ['netKcal' => $r['netKcal'], 'met' => $r['met']];
            }
            $class = match (true) {
                $eff <= 0 => 'rest', $eff < 45 => 'light', $eff < 90 => 'moderate',
                $eff < 180 => 'high', default => 'very_high',
            };
            $days[$d] = ['class' => $class, 'sessions' => $sessions, 'netKcal' => round($net)];
            $netByDay[$d] = $net;
        }
        $avgEEE = array_sum($netByDay) / 7;

        // 2. Safety floor from energy availability (>= 30 kcal/kg FFM/day, IOC RED-S)
        $kcal = (float) $p['kcalTarget'];
        $floor = 30.0 * $ffm + $avgEEE;
        if ($kcal < $floor) {
            $warnings[] = ['level' => 'critical', 'code' => 'energy_floor', 'msg' => sprintf('Target %d kcal is below the safe floor (%d kcal, energy availability <30 kcal/kg FFM). Raised to the floor.', $kcal, $floor)];
            $kcal = $floor;
        }

        // 3. Protein (ISSN 2017: 1.4-2.0 g/kg; 2.3-3.1 g/kg FFM when lean + hypocaloric)
        $protG = isset($p['proteinGPerKg']) ? $p['proteinGPerKg'] * $kg : match ($phase) {
            'cut' => max(2.0 * $kg, 2.3 * $ffm),
            'maintain' => 1.8 * $kg,
            default => 1.8 * $kg, // bulk (ISSN/Iraki 1.6-2.2)
        };
        $protG = min($protG, 0.35 * $kcal / 4);
        $protG = round($protG);

        // 4. Fat: floor 0.6 g/kg (literature minimum 0.5), target share by phase, ceiling 1.5 g/kg
        $fatFloor = max(0.6 * $kg, 0.15 * $kcal / 9);
        $fatShare = match ($phase) { 'cut' => 0.25, 'maintain' => 0.27, default => 0.25 };
        $fatG = max($fatFloor, $fatShare * $kcal / 9);
        $fatG = round(min($fatG, 1.5 * $kg));

        // 5. Carbs = remainder (or capped / keto)
        $mode = $p['carbMode'] ?? 'flexible';
        $weekCarbs = 7 * ($kcal - 4 * $protG - 9 * $fatG) / 4;
        if ($mode === 'flexible' && $weekCarbs < 7 * 3.0 * $kg && $weekCarbs >= 0) {
            $warnings[] = ['level' => 'warn', 'code' => 'low_carb_budget', 'msg' => 'Carbs fall below 3 g/kg after protein and fat. Consider a higher calorie target or a slower rate.'];
        }
        if ($weekCarbs < 0) {
            $warnings[] = ['level' => 'critical', 'code' => 'infeasible', 'msg' => 'Calories cannot cover protein + minimum fat. Raise the calorie target.'];
            $weekCarbs = 0;
        }
        if ($mode === 'keto') {
            $carbsKeto = 30.0;
            $fatG = round(($kcal - 4 * $protG - 4 * $carbsKeto) / 9);
            $weekCarbs = 7 * $carbsKeto;
            $warnings[] = ['level' => 'warn', 'code' => 'keto_training', 'msg' => 'Keto mode: glycolytic sessions (BJJ rolling, HIIT) will be under-fuelled. Consider a targeted carb dose pre-session.'];
        } elseif ($mode === 'cap' && isset($p['carbCapG'])) {
            $cap = min($weekCarbs / 7, (float) $p['carbCapG']);
            $fatG = min(round(1.5 * $kg), round(($kcal - 4 * $protG - 4 * $cap) / 9));
            $weekCarbs = 7 * $cap;
        }

        // 6. Periodise carbs across days (water-filling inside g/kg ranges)
        $k = (float) ($p['periodisation'] ?? 0.6);
        $mids = [];
        $mins = [];
        $maxs = [];
        foreach (self::DAYS as $d) {
            [$lo, $hi] = self::CARB_RANGE[$days[$d]['class']];
            $mids[$d] = ($lo + $hi) / 2 * $kg;
            $mins[$d] = $lo * $kg;
            $maxs[$d] = $hi * $kg;
        }
        $meanMid = array_sum($mids) / 7;
        $weights = [];
        foreach (self::DAYS as $d) {
            $weights[$d] = (1 - $k) + $k * ($mids[$d] / $meanMid);
        }
        $alloc = $mode === 'keto'
            ? array_fill_keys(self::DAYS, $weekCarbs / 7)
            : self::waterFill($weights, $mins, $maxs, $weekCarbs, $warnings);

        // 7. Assemble days
        $fiberPer1000 = 14.0; // IOM adequate-intake heuristic
        $out = [];
        $sumKcal = 0.0;
        foreach (self::DAYS as $d) {
            $c = round($alloc[$d]);
            $dayKcal = 4 * $protG + 9 * $fatG + 4 * $c;
            $ea = ($dayKcal - $days[$d]['netKcal']) / $ffm;
            $out[$d] = $days[$d] + [
                'kcal' => round($dayKcal), 'protein' => $protG, 'fat' => $fatG, 'carbs' => $c,
                'carbGPerKg' => round($c / $kg, 1),
                'fiber' => round($fiberPer1000 * $dayKcal / 1000),
                'energyAvailability' => round($ea, 1),
            ];
            $sumKcal += $dayKcal;
            [$lo] = self::CARB_RANGE[$days[$d]['class']];
            if ($mode === 'flexible' && $c / $kg < $lo - 0.01) {
                $warnings[] = ['level' => 'warn', 'code' => 'underfuelled', 'day' => $d, 'msg' => sprintf('%s: %.1f g/kg carbs is below the %.0f g/kg suggested for a %s day.', strtoupper($d), $c / $kg, $lo, $days[$d]['class'])];
            }
            if ($ea < 30) {
                $warnings[] = ['level' => 'warn', 'code' => 'low_ea_day', 'day' => $d, 'msg' => sprintf('%s: energy availability %.0f kcal/kg FFM is under 30.', strtoupper($d), $ea)];
            }
        }

        // per-meal protein dose (ISSN 0.25 g/kg or 20-40 g; Iraki/Helms 0.4-0.55 g/kg)
        $dose = max(20.0, min(50.0, 0.4 * $kg));
        $meals = (int) max(3, min(6, ceil($protG / $dose)));

        return [
            'days' => $out,
            'weekly' => [
                'kcalAvg' => round($sumKcal / 7), 'kcalTargetUsed' => round($kcal),
                'protein' => $protG, 'proteinGPerKg' => round($protG / $kg, 2),
                'fat' => $fatG, 'fatGPerKg' => round($fatG / $kg, 2),
                'carbsAvg' => round(array_sum($alloc) / 7), 'proteinPerMealG' => round($dose),
                'proteinMeals' => $meals, 'avgExerciseKcal' => round($avgEEE), 'ffmKg' => round($ffm, 1),
            ],
            'warnings' => $warnings,
        ];
    }

    /** Distribute $total across keys proportional to weights, honouring [min,max]; if impossible, degrade gracefully. */
    private static function waterFill(array $w, array $min, array $max, float $total, array &$warnings): array
    {
        $sumMin = array_sum($min);
        $sumMax = array_sum($max);
        if ($total <= $sumMin) {
            $f = $sumMin > 0 ? $total / $sumMin : 0;
            return array_map(fn($m) => $m * $f, $min);
        }
        if ($total >= $sumMax) { // more carbs than any day "needs": spread by weight, no cap
            $sw = array_sum($w);
            return array_map(fn($x) => $total * $x / $sw, $w);
        }
        $alloc = [];
        $free = array_keys($w);
        $remaining = $total;
        for ($iter = 0; $iter < 10 && $free; $iter++) {
            $sw = 0.0;
            foreach ($free as $d) $sw += $w[$d];
            $violated = false;
            $tmp = [];
            foreach ($free as $d) {
                $x = $remaining * $w[$d] / $sw;
                if ($x < $min[$d]) { $alloc[$d] = $min[$d]; $violated = true; }
                elseif ($x > $max[$d]) { $alloc[$d] = $max[$d]; $violated = true; }
                else $tmp[$d] = $x;
            }
            if (!$violated) { foreach ($tmp as $d => $x) $alloc[$d] = $x; break; }
            $free = array_values(array_diff($free, array_keys($alloc)));
            $remaining = $total - array_sum($alloc);
        }
        return $alloc;
    }
}
```

### src/PeriWorkout.php
```php
<?php
declare(strict_types=1);

namespace Engine;

/**
 * Pre / during / post session fuelling targets. These are carved OUT of the day's macros
 * (they shape distribution), never added on top.
 * Sources: ACSM/AND/DC 2016 (pre 1-4 g/kg in 1-4 h; post 1.0-1.2 g/kg/h x4h if <8 h to next
 * session; 30-60 g/h during >=60 min), ISSN 2017 (protein 0.25 g/kg or 20-40 g; Helms 0.4-0.5 g/kg
 * around resistance training). Fat/fibre caps are practical GI heuristics, not trial-derived.
 */
final class PeriWorkout
{
    /**
     * @param array{type:string,intensity:string,minutes:int} $session
     * @param ?float $hoursToNextSession null if none within 24 h
     * @param ?array{type:string,intensity:string,minutes:int} $nextSession
     */
    public static function plan(float $kg, array $session, ?float $hoursBeforeMeal = 2.0, ?float $hoursToNextSession = null, ?array $nextSession = null): array
    {
        $r = ExerciseCalculator::session($session['type'], $session['intensity'], $session['minutes'], $kg);
        $demand = $r['glycolytic'] && $r['met'] >= 7.0 ? 1.0 : ($r['met'] >= 5.0 ? 0.75 : 0.5);

        // PRE
        if ($hoursBeforeMeal === null) {
            $pre = ['skip' => true, 'note' => 'Training fasted/very light: acceptable for sessions <60 min at low-moderate intensity; hit the day total afterwards.'];
        } elseif ($hoursBeforeMeal < 1.0) {
            $pre = ['carbsG' => 25, 'proteinG' => 0, 'fatMaxG' => 3, 'fibreMaxG' => 3,
                'note' => 'Under an hour out: small, fast, easily digested carbs only (~25 g).'];
        } else {
            $h = min(4.0, $hoursBeforeMeal);
            $perKg = $h * $demand;                       // ~1 g/kg per hour of lead time, scaled by session demand
            $perKg = max(1.0, min(4.0, $perKg));
            $pre = [
                'carbsG' => (int) round($perKg * $kg),
                'proteinG' => (int) round(0.3 * $kg),
                'fatMaxG' => $h >= 3 ? 15 : ($h >= 2 ? 8 : 3),
                'fibreMaxG' => $h >= 3 ? 10 : 5,
                'note' => sprintf('%.1f g/kg carbs, low fat/fibre, moderate protein.', $perKg),
            ];
        }

        // DURING
        $during = ($session['minutes'] >= 60 && $r['met'] >= 8.0)
            ? ['carbsGPerHour' => [30, 60], 'note' => 'Hard session >=60 min: 30-60 g/h carbs plus fluids/sodium.']
            : ['carbsGPerHour' => [0, 0], 'note' => 'Water (+electrolytes if sweaty). Carbs optional.'];

        // POST
        $protein = (int) round(max(20.0, min(50.0, 0.4 * $kg)));
        $post = ['proteinG' => $protein, 'withinHours' => 2];
        $urgent = $hoursToNextSession !== null && $hoursToNextSession < 8.0;
        $nextGlyco = $nextSession !== null
            ? ExerciseCalculator::session($nextSession['type'], $nextSession['intensity'], $nextSession['minutes'], $kg)['glycolytic']
            : $r['glycolytic'];
        if ($urgent && $nextGlyco) {
            $post['carbsGPerHour'] = [round(1.0 * $kg), round(1.2 * $kg)];
            $post['forHours'] = 4;
            $post['note'] = 'Next glycolytic session <8 h away: 1.0-1.2 g/kg/h carbs for 4 h (ACSM), with protein.';
        } elseif ($urgent) {
            $post['carbsG'] = (int) round(1.0 * $kg);
            $post['note'] = 'Next session is resistance-only and <8 h away: ~1 g/kg carbs in the first 1-2 h is plenty.';
        } else {
            $post['carbsG'] = (int) round(0.75 * $kg);
            $post['note'] = '>8 h to next session: no special loading. Hit the day\'s carb target; ~0.75 g/kg in the post meal is a sensible default.';
        }

        return ['sessionNetKcal' => $r['netKcal'], 'pre' => $pre, 'during' => $during, 'post' => $post];
    }
}
```

### tests/macro_tests.php (run: php tests/macro_tests.php)
```php
<?php
declare(strict_types=1);
require __DIR__ . '/../src/ExerciseCalculator.php';
require __DIR__ . '/../src/MacroPlanner.php';
require __DIR__ . '/../src/PeriWorkout.php';
use Engine\{ExerciseCalculator as X, MacroPlanner as M, PeriWorkout as P};

$fails = 0;
function check(string $name, bool $ok, string $extra = ''): void { global $fails; echo ($ok ? '  ok  ' : ' FAIL ') . $name . ($extra ? "  [$extra]" : '') . "\n"; if (!$ok) $fails++; }

// Exercise maths
$bjj = X::session('bjj', 'standard', 60, 74);
check('BJJ 60min @74kg net = (7.5-1)*74 = 481', (int)$bjj['netKcal'] === 481, (string)$bjj['netKcal']);
check('range is +/-25%', $bjj['low'] < $bjj['netKcal'] && $bjj['high'] > $bjj['netKcal']);

$week = [
  'mon' => [['type'=>'bjj','intensity'=>'standard','minutes'=>60], ['type'=>'lifting','intensity'=>'heavy','minutes'=>50]],
  'tue' => [['type'=>'bjj','intensity'=>'standard','minutes'=>60]],
  'wed' => [['type'=>'lifting','intensity'=>'heavy','minutes'=>50]],
  'thu' => [['type'=>'bjj','intensity'=>'standard','minutes'=>60]],
  'fri' => [['type'=>'lifting','intensity'=>'heavy','minutes'=>50], ['type'=>'norwegian_4x4','intensity'=>'standard','minutes'=>40]],
  'sat' => [['type'=>'bjj','intensity'=>'standard','minutes'=>90]],
  'sun' => [],
];
$in = ['weightKg'=>74.0,'bodyFatPct'=>14.0,'phase'=>'bulk','kcalTarget'=>3150,'week'=>$week,'proteinGPerKg'=>2.2];
$plan = M::plan($in);

$sum = array_sum(array_column($plan['days'], 'kcal'));
check('weekly kcal conserved (|diff| <= 30)', abs($sum - 7*3150) <= 30, "sum=$sum vs ".(7*3150));
$w = $plan['weekly'];
check('protein 2.2 g/kg honoured', abs($w['proteinGPerKg'] - 2.2) < 0.02, (string)$w['proteinGPerKg']);
check('fat >= 0.6 g/kg floor', $w['fatGPerKg'] >= 0.6, (string)$w['fatGPerKg']);
$cs = array_column($plan['days'], 'carbs');
check('hard day carbs > rest day carbs', $plan['days']['sat']['carbs'] > $plan['days']['sun']['carbs'], "sat={$plan['days']['sat']['carbs']} sun={$plan['days']['sun']['carbs']}");
check('no negative macros', min($cs) >= 0 && $w['fat'] > 0);
check('carb g/kg stays within 3-12', min(array_column($plan['days'],'carbGPerKg')) >= 3.0 && max(array_column($plan['days'],'carbGPerKg')) <= 12.0);

// Aggressive cut that should trip guardrails
$cut = M::plan(['weightKg'=>74.0,'bodyFatPct'=>12.0,'phase'=>'cut','kcalTarget'=>1700,'week'=>$week]);
$codes = array_column($cut['warnings'], 'code');
check('unsafe cut is raised to energy-availability floor', in_array('energy_floor', $codes, true) && $cut['weekly']['kcalTargetUsed'] > 1700, (string)$cut['weekly']['kcalTargetUsed']);

// Keto mode flags training conflict
$keto = M::plan($in + ['carbMode'=>'keto']);
check('keto mode: carbs 30 g and warning', $keto['days']['mon']['carbs'] == 30 && in_array('keto_training', array_column($keto['warnings'],'code'), true));

// Peri-workout
$pw = P::plan(74.0, ['type'=>'bjj','intensity'=>'standard','minutes'=>60], 2.0, 5.0, ['type'=>'lifting','intensity'=>'heavy','minutes'=>50]);
check('pre carbs 1-4 g/kg window', $pw['pre']['carbsG'] >= 74 && $pw['pre']['carbsG'] <= 296, (string)$pw['pre']['carbsG']);
check('post protein 20-50 g', $pw['post']['proteinG'] >= 20 && $pw['post']['proteinG'] <= 50, (string)$pw['post']['proteinG']);
$pw2 = P::plan(74.0, ['type'=>'bjj','intensity'=>'hard','minutes'=>60], 2.0, 6.0, ['type'=>'hiit','intensity'=>'moderate','minutes'=>30]);
check('glycolytic double day -> 1.0-1.2 g/kg/h', ($pw2['post']['carbsGPerHour'][0] ?? 0) == 74 && $pw2['post']['carbsGPerHour'][1] == 89);

// Print worked example
echo "\n--- Worked example: 74 kg, 14% BF, lean bulk, 3150 kcal avg, 2.2 g/kg protein ---\n";
printf("%-4s %-9s %5s %5s %4s %5s %6s %5s %4s\n", 'Day','Class','kcal','P','F','C','C g/kg','Train','EA');
foreach ($plan['days'] as $d => $r) printf("%-4s %-9s %5d %5d %4d %5d %6.1f %5d %4.0f\n", $d, $r['class'], $r['kcal'], $r['protein'], $r['fat'], $r['carbs'], $r['carbGPerKg'], $r['netKcal'], $r['energyAvailability']);
echo "\nweekly: " . json_encode($plan['weekly']) . "\n";
echo "warnings: " . json_encode(array_column($plan['warnings'],'code')) . "\n";
echo "\nPeri (Mon BJJ, meal 2h before, lift in 5h): " . json_encode($pw) . "\n";
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit($fails ? 1 : 0);
```
