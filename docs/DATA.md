# Food data (M2)

FUEL searches a local PostgreSQL copy of two public food databases plus your own foods. Nothing is looked up live while you search.

| Source | What | Licence / attribution |
|---|---|---|
| **AFCD Release 3** (FSANZ) | 1,588 generic and whole foods, up to ~170 nutrients each | CC BY 4.0 Australia. Attribution: "© Food Standards Australia New Zealand". Must not imply FSANZ endorses FUEL. Verified on foodstandards.gov.au/copyright. Shown in **Security → About & data sources**. |
| **Open Food Facts** (AU subset) | Packaged products with barcodes | ODbL (database) + DbCL (contents). Attribution and share-alike. Shown in About. |
| **Your foods** | Added in M4/M5 | Yours. Rank first. |

## Importing

Run these inside the app container (Coolify: app resource, Terminal, `app`), or locally.

```bash
php artisan foods:import-afcd --download --cleanup   # ~1 minute, ~4 MB download
php artisan foods:import-off  --download --cleanup   # downloads ~1.5 GB, scans it, keeps only en:australia rows (10-20 minutes)
php artisan foods:reindex                            # refresh planner statistics
```

Both importers are idempotent: re-running updates rows in place (keyed on AFCD key / barcode) and never touches your own foods. If `--download` fails (FSANZ renamed a file), download by hand into `storage/imports/afcd/` (names: `Food-Details.xlsx`, `Nutrient-profiles.xlsx`, `Food-group-information.xlsx`) or `storage/imports/off/off.csv.gz` and run without `--download`.

Optional weekly OFF refresh: set `FUEL_OFF_AUTO_IMPORT=true` (Sundays 03:30 Melbourne time, then reindex). Off by default because it moves 1.5 GB.
Open Food Facts asks bots for a User-Agent; the default is generic. Set `FUEL_OFF_USER_AGENT="FUEL/1.0 (you@example.com)"` if you want them to be able to contact you.

## Decisions recorded here (BLUEPRINT 5.1 asks for this)

- **Energy:** AFCD has two columns. FUEL uses "Energy with dietary fibre, equated (kJ)", the figure Australian labels use, converted at 4.184 kJ/kcal. Every AFCD food uses the same column.
- **Carbohydrate:** "Available carbohydrate, with sugar alcohols" (falls back to "without").
- **Liquids:** foods on AFCD's "Liquids only per 100 mL" sheet are stored per 100 mL with `is_liquid = true` and a density from specific gravity. Everything else is per 100 g.
- **Long tail:** every other AFCD numeric column goes to `food_nutrients` under a key like `iron_fe_mg`, ready for the M7 micronutrient view.
- **Quality score** (feeds 10% of search ranking): analysed 90, calculated/recipe 80, borrowed 70, imputed 60. OFF rows start at 60 and gain 5 each for a serving size, fibre, and sodium; flagged rows lose 15.
- **OFF validation:** rejects missing macros, macros over 100 g, kcal above 900, and rows where kcal differs from 4P+4C+9F by more than 20% (when kcal > 50). A 10-20% difference is kept and flagged `needs_check`. OFF is crowd-sourced, so treat it as "check the pack".
- **Household measures:** Australian metric: cup 250 mL, tablespoon 20 mL, teaspoon 5 mL. A cup of a *solid* has no weight unless the food has a stored serving. Counts ("2 eggs") only get grams when a serving exists. FUEL never guesses a weight; the Add screen (M3) will ask.

## Search

`GET /foods/search?q=...` (JSON; signed-in only). Postgres `tsvector` + `pg_trgm` over an unaccented, lowercased name; AU synonyms from `data/synonyms.json` (edit it, no code change); plurals; typo fallback; quantity parsing (`150g chicken breast`, `2 eggs`, `300ml milk`, `weetbix 2`).

Ranking (BLUEPRINT 5.3): `0.40 trigram + 0.20 token match + 0.20 personal + 0.10 source + 0.10 quality - missing-macro penalty`. Token match also rewards names that *start* with the query. A personal alias outranks everything. The personal term uses use count and recency; the time-of-day boost waits for food logging (M3).

Benchmark: `php artisan foods:bench` adds 200k synthetic rows, runs 16 typical queries, prints p50/p95, removes the rows. Last local result on PostgreSQL 18: p50 42 ms, p95 74 ms (limit 150 ms). CI runs it.

## Barcodes and your own foods (M4)

`GET /foods/barcode?code=...` resolves a scan in this order:

1. **Local database**, trying the other spellings of the same product (UPC-A, EAN-13 with a leading zero, GTIN-14). About 40 ms.
2. **Live Open Food Facts lookup** (`/api/v2/product/{code}`, your `FUEL_OFF_USER_AGENT`, 6 s timeout), only when the code passes the GTIN check digit. A usable product is saved to `foods` for good, so each product is fetched at most once (about 900 ms the first time, 40 ms after). A miss is remembered for 6 hours. A timeout, rate limit or outage reports `offline` and caches nothing.
3. **Not found / incomplete** (product exists but has no usable nutrition): the app offers "Add it", pre-filled with the barcode and whatever OFF knows (name, brand, serving).

Statuses: `found`, `invalid` (bad length or check digit, nearly always a camera misread), `not_found`, `incomplete`, `offline`.

**Scanning.** Native `BarcodeDetector` where the browser can read EAN-13, otherwise the zxing WASM decoder (about 1 MB, loaded only then, served from this origin, not precached). Formats: EAN-13, EAN-8, UPC-A. The camera needs HTTPS (fine on Coolify) or localhost. Every scan screen also has a "type the barcode" box, which is the fallback when the camera is denied. "Scan several" logs each product at its usual amount and keeps scanning.

**Your foods** (`/foods`, linked from Security): create from a nutrition panel per 100 g or per serve (converted for you), optional fibre/sugar/sat fat/sodium/barcode, a duplicate warning from the same search, edit, delete. Impossible values are refused with a plain message (macros over 100 g per 100 g, over 900 kcal per 100 g). Calories more than 20% away from 4P+4C+9F are kept but flagged "check". Deleting a food never changes logged history.

Not built: photo of the nutrition panel (needs an Anthropic key; BLUEPRINT marks it optional).

