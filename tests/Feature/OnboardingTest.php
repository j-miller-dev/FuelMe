<?php

use App\Models\Goal;
use App\Models\TargetHistory;
use App\Models\User;
use App\Models\WeightLog;
use Inertia\Testing\AssertableInertia as Assert;

function onboardingInput(array $over = []): array
{
    return $over + [
        'sex' => 'male', 'dob' => now()->subYears(30)->subDays(10)->toDateString(), 'height_cm' => 180, 'weight_kg' => 74, 'body_fat_pct' => 14,
        'job' => 'on_feet', 'shift' => 'afternoon', 'training_age' => '1to3', 'lifting_style' => 'hit', 'energy_unit' => 'kcal',
        'phase' => 'bulk', 'rate_pct_per_week' => null, 'protein_g_per_kg' => 2.2, 'acknowledged' => true,
    ];
}

beforeEach(fn () => $this->user = User::factory()->create());

/** Act as the user with a fresh model per request, like production (no relations cached from earlier requests). */
function me()
{
    return test()->actingAs(test()->user->fresh());
}

it('sends a new user to onboarding before anything else', function () {
    me()->get('/')->assertRedirect('/onboarding');
    me()->get('/onboarding')->assertInertia(fn (Assert $p) => $p
        ->component('Onboarding')->where('editing', false)->where('defaults', null)
        ->where('rateDefaults.intermediate.bulk', 0.3)->where('rateDefaults.novice.cut', -0.5));
});

it('produces a prior TDEE and first targets (M1 acceptance)', function () {
    me()->post('/onboarding', onboardingInput())->assertRedirect('/');

    $profile = $this->user->fresh()->profile;
    expect($profile->job)->toBe('on_feet')->and($profile->disclaimer_ack_at)->not->toBeNull();

    $goal = Goal::where('user_id', $this->user->id)->sole();
    expect($goal->phase)->toBe('bulk')->and($goal->rate_pct_per_week)->toBe(0.3); // intermediate default
    expect(WeightLog::where('user_id', $this->user->id)->sole()->kg)->toBe(74.0);

    $t = TargetHistory::where('user_id', $this->user->id)->sole();
    $plan = $t->plan_json;
    expect($t->reason)->toBe('onboarding')
        ->and($plan['prior']['rmrMethod'])->toBe('katch_mcardle')
        ->and($plan['prior']['sd'])->toEqual(350)
        ->and($plan['prior']['tdee'])->toBeBetween(2600, 3400)           // plausible for 74 kg, on his feet, 5 sessions/wk
        ->and($t->kcal)->toBeGreaterThan($plan['prior']['tdee'])           // a bulk eats above expenditure
        ->and($t->protein)->toBe((int) round(2.2 * 74))                    // override honoured
        ->and($t->fat)->toBeGreaterThanOrEqual((int) floor(0.6 * 74))
        ->and($plan['plan']['days'])->toHaveCount(7);
});

it('renders the plan page from the stored targets, in kJ when asked', function () {
    me()->post('/onboarding', onboardingInput(['energy_unit' => 'kj']));

    me()->get('/plan')->assertInertia(fn (Assert $p) => $p
        ->component('Plan')
        ->where('energyUnit', 'kj')
        ->where('goal.phase', 'bulk')
        ->has('days', 7)
        ->has('why')
        ->where('weekly.protein', 163));
});

it('does not add exercise calories on top of the target', function () {
    me()->post('/onboarding', onboardingInput());
    $t = TargetHistory::sole();
    // weekly average kcal equals the engine's smoothed target, not target + exercise
    expect($t->plan_json['plan']['weekly']['kcalAvg'])->toEqualWithDelta($t->plan_json['kcalTarget'], 30);
});

it('requires the not-medical-advice acknowledgement on first run', function () {
    me()->post('/onboarding', onboardingInput(['acknowledged' => false]))->assertSessionHasErrors('acknowledged');
    expect(TargetHistory::count())->toBe(0);
});

it('only serves adults', function () {
    me()->post('/onboarding', onboardingInput(['dob' => now()->subYears(17)->toDateString()]))->assertSessionHasErrors('dob');
});

it('rejects rates above the safe caps with a plain-language reason', function () {
    me()->post('/onboarding', onboardingInput(['rate_pct_per_week' => 0.6]))
        ->assertSessionHasErrors('rate_pct_per_week');
    expect(session('errors')->first('rate_pct_per_week'))->toContain('0.35'); // names the ceiling for intermediate lifters
    me()->post('/onboarding', onboardingInput(['phase' => 'cut', 'rate_pct_per_week' => -1.4]))
        ->assertSessionHasErrors('rate_pct_per_week');
    expect(TargetHistory::count())->toBe(0);
});

it('will not offer a cut to someone already very lean', function () {
    me()->post('/onboarding', onboardingInput(['phase' => 'cut', 'body_fat_pct' => 8]))->assertSessionHasErrors('phase');
    me()->post('/onboarding', onboardingInput(['phase' => 'cut', 'body_fat_pct' => null, 'weight_kg' => 58]))->assertSessionHasErrors('phase');
    expect(TargetHistory::count())->toBe(0);
});

it('lets a cut through when the numbers are safe', function () {
    me()->post('/onboarding', onboardingInput(['phase' => 'cut', 'body_fat_pct' => 18]))->assertRedirect('/');
    $t = TargetHistory::sole();
    expect($t->kcal)->toBeLessThan($t->plan_json['prior']['tdee']);
});

it('recomputes on profile edit and keeps the history', function () {
    me()->post('/onboarding', onboardingInput());
    me()->get('/onboarding')->assertRedirect('/'); // onboarding is one-time

    me()->get('/profile')->assertInertia(fn (Assert $p) => $p->where('editing', true)->where('defaults.weight_kg', 74)->where('defaults.phase', 'bulk'));

    me()->put('/profile', onboardingInput(['weight_kg' => 76, 'phase' => 'maintain', 'acknowledged' => null]))->assertRedirect('/');

    expect(TargetHistory::count())->toBe(2)
        ->and(TargetHistory::latest('id')->first()->reason)->toBe('profile_edit')
        ->and(Goal::count())->toBe(2)
        ->and($this->user->fresh()->goal->phase)->toBe('maintain')
        ->and(WeightLog::sole()->kg)->toBe(76.0); // same local day: updated in place, not duplicated
});

it('keeps one user from seeing another user\'s plan', function () {
    me()->post('/onboarding', onboardingInput());
    $other = User::factory()->create();
    test()->actingAs($other)->get('/')->assertRedirect('/onboarding');
});
