-- FUEL schema (PostgreSQL 18). Reference for M1 Laravel migrations. Single user, but user_id kept everywhere.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;

CREATE TABLE users (
  id bigserial PRIMARY KEY, name text NOT NULL, email text UNIQUE NOT NULL, password text,
  created_at timestamptz DEFAULT now(), updated_at timestamptz DEFAULT now()
);
CREATE TABLE profiles (
  user_id bigint PRIMARY KEY REFERENCES users ON DELETE CASCADE,
  sex text CHECK (sex IN ('male','female')), dob date, height_cm numeric(5,1), tz text DEFAULT 'Australia/Melbourne',
  units text DEFAULT 'metric', rollover_hour smallint DEFAULT 3, body_fat_pct numeric(4,1), training_age text,
  activity_level numeric(3,2) DEFAULT 1.4, carb_mode text DEFAULT 'flexible' CHECK (carb_mode IN ('flexible','cap','keto')),
  protein_g_per_kg_override numeric(3,2), created_at timestamptz DEFAULT now(), updated_at timestamptz DEFAULT now()
);

CREATE TABLE foods (
  id bigserial PRIMARY KEY, user_id bigint REFERENCES users ON DELETE CASCADE,
  source text NOT NULL CHECK (source IN ('user','afcd','off','recipe')), source_id text, barcode text,
  name text NOT NULL, brand text, stores text[] DEFAULT '{}', category text,
  state text CHECK (state IN ('raw','cooked','dry','as_sold')),
  per100_kcal numeric(7,2), per100_protein numeric(6,2), per100_carbs numeric(6,2), per100_fat numeric(6,2),
  per100_fibre numeric(6,2), per100_sugar numeric(6,2), per100_sat_fat numeric(6,2), per100_sodium numeric(8,2),
  is_liquid boolean DEFAULT false, density_g_ml numeric(5,3), quality smallint DEFAULT 50, needs_check boolean DEFAULT false,
  tsv tsvector GENERATED ALWAYS AS (
    setweight(to_tsvector('simple', unaccent(coalesce(name,''))), 'A') ||
    setweight(to_tsvector('simple', unaccent(coalesce(brand,''))), 'B') ||
    setweight(to_tsvector('simple', unaccent(coalesce(category,''))), 'C')) STORED,
  last_used_at timestamptz, use_count int DEFAULT 0,
  created_at timestamptz DEFAULT now(), updated_at timestamptz DEFAULT now()
);
CREATE INDEX foods_tsv_idx ON foods USING gin (tsv);
CREATE INDEX foods_name_trgm ON foods USING gin (name gin_trgm_ops);
CREATE UNIQUE INDEX foods_barcode_uq ON foods (barcode) WHERE barcode IS NOT NULL;
CREATE UNIQUE INDEX foods_source_uq ON foods (source, source_id) WHERE source_id IS NOT NULL;

CREATE TABLE food_servings (id bigserial PRIMARY KEY, food_id bigint NOT NULL REFERENCES foods ON DELETE CASCADE, label text NOT NULL, grams numeric(8,2) NOT NULL);
CREATE TABLE food_nutrients (food_id bigint REFERENCES foods ON DELETE CASCADE, nutrient text, per100 numeric(12,4), PRIMARY KEY (food_id, nutrient));
CREATE TABLE food_aliases (
  id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE,
  phrase text NOT NULL, food_id bigint NOT NULL REFERENCES foods ON DELETE CASCADE, created_at timestamptz DEFAULT now(),
  UNIQUE (user_id, phrase)
);

CREATE TABLE meals (
  id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE, name text NOT NULL,
  yield_portions numeric(6,2) DEFAULT 1, cook_loss numeric(4,3) DEFAULT 0, tags text[] DEFAULT '{}',
  created_at timestamptz DEFAULT now(), updated_at timestamptz DEFAULT now()
);
CREATE TABLE meal_items (id bigserial PRIMARY KEY, meal_id bigint NOT NULL REFERENCES meals ON DELETE CASCADE,
  food_id bigint REFERENCES foods, name text, grams numeric(8,2), qty numeric(8,2), unit text);

CREATE TABLE capture_sessions (
  id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE,
  transcript text, parsed_json jsonb, outcome text, latency_ms int, cost_usd numeric(8,5), created_at timestamptz DEFAULT now()
);
CREATE TABLE food_logs (
  id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE,
  local_date date NOT NULL, logged_at timestamptz NOT NULL DEFAULT now(), meal_slot text,
  food_id bigint REFERENCES foods, meal_id bigint REFERENCES meals, grams numeric(8,2),
  kcal numeric(7,2) NOT NULL, protein numeric(6,2) NOT NULL, carbs numeric(6,2) NOT NULL, fat numeric(6,2) NOT NULL, fibre numeric(6,2),
  source text CHECK (source IN ('search','scan','capture','quick','copy')),
  capture_session_id bigint REFERENCES capture_sessions, idempotency_key uuid UNIQUE,
  created_at timestamptz DEFAULT now(), updated_at timestamptz DEFAULT now()
);
CREATE INDEX food_logs_day ON food_logs (user_id, local_date);

CREATE TABLE weight_logs (id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE,
  local_date date NOT NULL, kg numeric(5,2) NOT NULL, source text DEFAULT 'manual', created_at timestamptz DEFAULT now(),
  UNIQUE (user_id, local_date));
CREATE TABLE body_measurements (id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE,
  local_date date NOT NULL, site text NOT NULL, value numeric(6,2) NOT NULL);

CREATE TABLE workout_logs (
  id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE, local_date date NOT NULL,
  type text NOT NULL, intensity text NOT NULL, minutes smallint NOT NULL, rpe numeric(3,1), met numeric(4,2),
  net_kcal numeric(7,2), started_at timestamptz, source text DEFAULT 'manual', created_at timestamptz DEFAULT now()
);
CREATE TABLE training_template (id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE,
  dow smallint NOT NULL CHECK (dow BETWEEN 0 AND 6), start_time time, type text NOT NULL, intensity text NOT NULL, minutes smallint NOT NULL);

CREATE TABLE goals (id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE,
  phase text NOT NULL CHECK (phase IN ('cut','maintain','bulk')), rate_pct_per_week numeric(4,2) NOT NULL,
  start_date date NOT NULL, event_date date, experience text);
CREATE TABLE engine_snapshots (user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE, local_date date NOT NULL,
  trend_kg numeric(6,3), tdee numeric(7,1), tdee_sd numeric(6,1), filter_version text, PRIMARY KEY (user_id, local_date));
CREATE TABLE target_history (id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE,
  effective_from date NOT NULL, kcal int, protein int, fat int, carbs int, reason text, plan_json jsonb);

-- Shopping (blueprint 5.6)
CREATE TABLE shopping_lists (id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE,
  name text, archived_at timestamptz, created_at timestamptz DEFAULT now(), updated_at timestamptz DEFAULT now());
CREATE TABLE shopping_list_recipes (list_id bigint REFERENCES shopping_lists ON DELETE CASCADE, meal_id bigint REFERENCES meals, servings numeric(6,2) NOT NULL, PRIMARY KEY (list_id, meal_id));
CREATE TABLE shopping_list_items (
  id bigserial PRIMARY KEY, list_id bigint NOT NULL REFERENCES shopping_lists ON DELETE CASCADE,
  ingredient_key text NOT NULL, name text NOT NULL, qty numeric(10,2), unit text, aisle text,
  status text NOT NULL DEFAULT 'need' CHECK (status IN ('need','have','bought')), is_manual boolean DEFAULT false,
  UNIQUE (list_id, ingredient_key)
);
CREATE TABLE pantry_items (id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users ON DELETE CASCADE,
  ingredient_key text NOT NULL, qty numeric(10,2), unit text, always_have boolean DEFAULT false, UNIQUE (user_id, ingredient_key));

CREATE TABLE settings (user_id bigint PRIMARY KEY REFERENCES users ON DELETE CASCADE, data jsonb NOT NULL DEFAULT '{}');
