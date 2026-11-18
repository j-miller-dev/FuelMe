<?php

return [
    /*
     | Registration is open only while REGISTRATION_ENABLED is true AND no user exists yet
     | (single-user app). After the first account is created it closes by itself.
     */
    'registration_enabled' => env('REGISTRATION_ENABLED', true),

    'default_timezone' => env('FUEL_TIMEZONE', 'Australia/Melbourne'),

    // Day rolls over at this local hour so late-night eating lands on the right day (BLUEPRINT section 3).
    'rollover_hour' => (int) env('FUEL_ROLLOVER_HOUR', 3),

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
            'mon' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60], ['type' => 'lifting', 'intensity' => 'hit', 'minutes' => 40]],
            'tue' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60]],
            'wed' => [['type' => 'lifting', 'intensity' => 'hit', 'minutes' => 40]],
            'thu' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60]],
            'fri' => [['type' => 'lifting', 'intensity' => 'hit', 'minutes' => 40], ['type' => 'norwegian_4x4', 'intensity' => 'standard', 'minutes' => 40]],
            'sat' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 90]],
            'sun' => [],
        ],
        'volume' => [
            'mon' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60], ['type' => 'lifting', 'intensity' => 'heavy', 'minutes' => 50]],
            'tue' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60]],
            'wed' => [['type' => 'lifting', 'intensity' => 'heavy', 'minutes' => 50]],
            'thu' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60]],
            'fri' => [['type' => 'lifting', 'intensity' => 'heavy', 'minutes' => 50], ['type' => 'norwegian_4x4', 'intensity' => 'standard', 'minutes' => 40]],
            'sat' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 90]],
            'sun' => [],
        ],
    ],
];
