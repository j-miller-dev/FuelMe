<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\Profile;
use App\Models\TargetHistory;
use App\Models\User;
use App\Models\WeightLog;
use Illuminate\Support\Facades\DB;

/** Persists onboarding / profile edits and the first (or a recomputed) set of targets. */
class OnboardingService
{
    public function __construct(private TargetService $targets) {}

    /**
     * @param array<string,mixed> $in validated input from OnboardingRequest
     */
    public function apply(User $user, array $in, string $reason): TargetHistory
    {
        return DB::transaction(function () use ($user, $in, $reason) {
            $existing = $user->profile;
            $tz = $existing->tz ?? config('fuel.default_timezone');
            $today = now($tz)->toDateString();

            $profile = Profile::updateOrCreate(['user_id' => $user->id], [
                'sex' => $in['sex'],
                'dob' => $in['dob'],
                'height_cm' => $in['height_cm'],
                'body_fat_pct' => $in['body_fat_pct'] ?? null,
                'tz' => $tz,
                'energy_unit' => $in['energy_unit'],
                'rollover_hour' => $existing->rollover_hour ?? config('fuel.rollover_hour'),
                'job' => $in['job'],
                'shift' => $in['shift'],
                'training_age' => $in['training_age'],
                'lifting_style' => $in['lifting_style'],
                'protein_g_per_kg_override' => $in['protein_g_per_kg'] ?? null,
                'disclaimer_ack_at' => $existing?->disclaimer_ack_at ?? now(),
            ]);

            // First weigh-in. The Kalman trend and logging UI arrive in M7.
            WeightLog::updateOrCreate(
                ['user_id' => $user->id, 'local_date' => $today],
                ['kg' => $in['weight_kg'], 'source' => 'onboarding'],
            );

            $computed = $this->targets->compute([
                'sex' => $profile->sex,
                'dob' => $profile->dob->toDateString(),
                'height_cm' => $profile->height_cm,
                'weight_kg' => (float) $in['weight_kg'],
                'body_fat_pct' => $profile->body_fat_pct,
                'job' => $profile->job,
                'training_age' => $profile->training_age,
                'lifting_style' => $profile->lifting_style,
                'phase' => $in['phase'],
                'rate_pct_per_week' => $in['rate_pct_per_week'] ?? null,
                'protein_g_per_kg' => $profile->protein_g_per_kg_override,
                'carb_mode' => $profile->carb_mode,
            ], $today);

            Goal::create([
                'user_id' => $user->id,
                'phase' => $in['phase'],
                'rate_pct_per_week' => $computed['ratePctPerWeek'],
                'start_date' => $today,
                'experience' => $computed['experience'],
            ]);

            $weekly = $computed['plan']['weekly'];

            // Keep the caller's in-memory user consistent with what was just written.
            $user->setRelation('profile', $profile);
            $user->unsetRelation('goal');

            return TargetHistory::create([
                'user_id' => $user->id,
                'effective_from' => $today,
                'kcal' => (int) $weekly['kcalAvg'],
                'protein' => (int) $weekly['protein'],
                'fat' => (int) $weekly['fat'],
                'carbs' => (int) $weekly['carbsAvg'],
                'reason' => $reason,
                'plan_json' => $computed + [
                    'inputs' => ['weightKg' => (float) $in['weight_kg'], 'style' => $profile->lifting_style, 'trainingWeek' => 'seed_week'],
                ],
            ]);
        });
    }
}
