<?php

return [
    /*
     | Registration is open only while REGISTRATION_ENABLED is true AND no user exists yet
     | (single-user app). After the first account is created it closes by itself.
     */
    'registration_enabled' => env('REGISTRATION_ENABLED', true),

    // A mistyped FUEL_TIMEZONE (e.g. "0") must not take the Today page down: anything that is not a real zone name falls back.
    'default_timezone' => in_array(env('FUEL_TIMEZONE'), timezone_identifiers_list(), true) ? env('FUEL_TIMEZONE') : 'Australia/Melbourne',

    // Day rolls over at this local hour so late-night eating lands on the right day (BLUEPRINT section 3).
    'rollover_hour' => (int) env('FUEL_ROLLOVER_HOUR', 3),

    // Open Food Facts asks automated clients for a custom User-Agent. Generic by default; add your own contact if you like.
    'off_user_agent' => env('FUEL_OFF_USER_AGENT', 'FUEL/1.0 (self-hosted personal nutrition app)'),

    // Live barcode lookups (BLUEPRINT 5.4). Override only to point at a mirror or a test server.
    'off_api_base' => env('FUEL_OFF_API_BASE', 'https://world.openfoodfacts.org'),

    // Weekly refresh of the Australian Open Food Facts subset (downloads ~1.5 GB, then deletes it). Off by default.
    'off_auto_import' => (bool) env('FUEL_OFF_AUTO_IMPORT', false),

    // 7700 kcal per kg of body-weight change (BLUEPRINT 9.1; configurable).
    'kcal_per_kg' => (float) env('FUEL_KCAL_PER_KG', 7700),

    /*
     | PLACEHOLDER until M8 (training template + logging): the owner's seed training week from
     | BLUEPRINT section 12 (BJJ Mon/Tue/Thu/Sat, lifting Mon/Wed/Fri, Norwegian 4x4 Fri).
     | Lifting uses the HIT intensity (MET 3.5 ESTIMATE, 40 min) because that is the default lifting style.
     | The onboarding plan is labelled "based on a default training week" in the UI.
     */
    'seed_week' => [
        'hit' => [
            'mon' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60, 'at' => '06:30'], ['type' => 'lifting', 'intensity' => 'hit', 'minutes' => 40, 'at' => '12:00']],
            'tue' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60, 'at' => '11:30']],
            'wed' => [['type' => 'lifting', 'intensity' => 'hit', 'minutes' => 40, 'at' => '12:00']],
            'thu' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60, 'at' => '11:30']],
            'fri' => [['type' => 'lifting', 'intensity' => 'hit', 'minutes' => 40, 'at' => '12:00'], ['type' => 'norwegian_4x4', 'intensity' => 'standard', 'minutes' => 40]],
            'sat' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 90, 'at' => '10:00']],
            'sun' => [],
        ],
        'volume' => [
            'mon' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60, 'at' => '06:30'], ['type' => 'lifting', 'intensity' => 'heavy', 'minutes' => 50, 'at' => '12:00']],
            'tue' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60, 'at' => '11:30']],
            'wed' => [['type' => 'lifting', 'intensity' => 'heavy', 'minutes' => 50, 'at' => '12:00']],
            'thu' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60, 'at' => '11:30']],
            'fri' => [['type' => 'lifting', 'intensity' => 'heavy', 'minutes' => 50, 'at' => '12:00'], ['type' => 'norwegian_4x4', 'intensity' => 'standard', 'minutes' => 40]],
            'sat' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 90, 'at' => '10:00']],
            'sun' => [],
        ],
    ],

    /*
    | AI meal capture (BLUEPRINT 6). With no key it runs in basic mode (rule-based parser), which always works.
    | The model only PARSES text into foods and amounts; every number comes from the food database.
    | Prices are not assumed: the cap is on TOKENS per calendar month.
    */
    'hevy' => [
        'base_url' => env('FUEL_HEVY_BASE_URL', 'https://api.hevyapp.com'),
        'timeout' => 20,
        'plate_step_kg' => (float) env('FUEL_PLATE_STEP_KG', 2.5),   // ASSUMPTION used for the "small load step" suggestion
    ],

    // Web Push (phone notifications). Generate a key pair once with `php artisan push:keys` and set both in the server environment.
    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'https://localhost')),   // a URL or mailto: that identifies this server to the push service
    ],

    'intervals' => [
        'base_url' => env('FUEL_INTERVALS_BASE_URL', 'https://intervals.icu'),
        'timeout' => 20,
    ],

    'capture' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('FUEL_CAPTURE_MODEL', 'claude-haiku-5-5'),
        'api_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'),
        'timeout' => (int) env('FUEL_CAPTURE_TIMEOUT', 8),
        'monthly_token_cap' => (int) env('FUEL_CAPTURE_MONTHLY_TOKENS', 2000000),
        'max_chars' => 600,
    ],

    /*
    | Weekly review (BLUEPRINT 9A.3). The template note always works. With an API key the model may reword it, but only if it adds no number.
    */
    'review' => [
        'use_model' => (bool) env('FUEL_REVIEW_USE_MODEL', true),
    ],

    /*
    | Coach chat (BLUEPRINT 9A.2). Needs ANTHROPIC_API_KEY. Read-only tools only; every number in an answer is checked against the
    | tool results, and an answer that fails the check is replaced. Set FUEL_COACH_ENABLED=false to switch the coach off entirely.
    */
    'coach' => [
        'enabled' => (bool) env('FUEL_COACH_ENABLED', true),
        'model' => env('FUEL_COACH_MODEL', 'claude-sonnet-5-5'),
        'timeout' => (int) env('FUEL_COACH_TIMEOUT', 40),
        'monthly_token_cap' => (int) env('FUEL_COACH_MONTHLY_TOKENS', 1000000),
        'max_chars' => 500,
        'persona' => env('FUEL_COACH_PERSONA', 'trainer'),
        'max_rounds' => 6,
    ],
];
