<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// BLUEPRINT 5.2: refresh Open Food Facts weekly. Opt-in (FUEL_OFF_AUTO_IMPORT=true) because it moves 1.5 GB.
if (config('fuel.off_auto_import')) {
    Schedule::command('foods:import-off --download --cleanup')->weeklyOn(0, '03:30')->timezone(config('fuel.default_timezone'))
        ->withoutOverlapping(360)->runInBackground();
    Schedule::command('foods:reindex')->weeklyOn(0, '05:30')->timezone(config('fuel.default_timezone'));
}

// BLUEPRINT 9A.3: the weekly review. Runs hourly; the command itself only acts on Sunday from 18:00 in the user's timezone, once per week.
Schedule::command('review:weekly')->hourly()->withoutOverlapping(30);
Schedule::command('hevy:sync')->everyThirtyMinutes()->withoutOverlapping(25);
