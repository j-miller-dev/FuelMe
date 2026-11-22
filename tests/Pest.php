<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests boot Laravel with a fresh in-memory database.
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

// Engine tests are PURE PHP (BLUEPRINT rule): no TestCase, no container, no database.
pest()->in('Engine');

/** A signed-in user who has finished onboarding (so a plan exists). */
function loggedInUser(): \App\Models\User
{
    $u = \App\Models\User::factory()->create();
    test()->actingAs($u)->post('/onboarding', [
        'sex' => 'male', 'dob' => now()->subYears(30)->subDays(10)->toDateString(), 'height_cm' => 180, 'weight_kg' => 74, 'body_fat_pct' => 14,
        'job' => 'on_feet', 'shift' => 'afternoon', 'training_age' => '1to3', 'lifting_style' => 'hit', 'energy_unit' => 'kcal',
        'phase' => 'bulk', 'rate_pct_per_week' => null, 'protein_g_per_kg' => 2.2, 'acknowledged' => true,
    ])->assertRedirect('/');

    return $u->fresh();
}

