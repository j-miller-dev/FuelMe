<?php

namespace App\Http\Requests;

use App\Domain\Nutrition\GoalRates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $first = $this->user()->profile === null;

        return [
            'sex' => ['required', Rule::in(['male', 'female'])],
            // FUEL's targets and guardrails are written for adults.
            'dob' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'height_cm' => ['required', 'numeric', 'between:120,230'],
            'weight_kg' => ['required', 'numeric', 'between:35,300'],
            'body_fat_pct' => ['nullable', 'numeric', 'between:3,60'],
            'job' => ['required', Rule::in(['desk', 'mixed', 'on_feet', 'heavy'])],
            'shift' => ['required', Rule::in(['day', 'afternoon', 'night', 'varied'])],
            'training_age' => ['required', Rule::in(['lt1', '1to3', 'gt3'])],
            'lifting_style' => ['required', Rule::in(['hit', 'volume'])],
            'energy_unit' => ['required', Rule::in(['kcal', 'kj'])],
            'phase' => ['required', Rule::in(['cut', 'maintain', 'bulk'])],
            'rate_pct_per_week' => ['nullable', 'numeric', 'between:-1.5,1.5'],
            'protein_g_per_kg' => ['nullable', 'numeric', 'between:1.2,3.1'],
            'acknowledged' => [$first ? 'accepted' : 'sometimes'],
        ];
    }

    public function messages(): array
    {
        return [
            'dob.before_or_equal' => 'FUEL is for adults (18 and over).',
            'acknowledged.accepted' => 'Please confirm you have read the note that this is not medical advice.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $d = $v->validated();
            $experience = GoalRates::experience($d['training_age']);
            $rate = isset($d['rate_pct_per_week']) ? (float) $d['rate_pct_per_week'] : GoalRates::default($d['phase'], $experience);

            if ($reason = GoalRates::check($d['phase'], $experience, $rate)) {
                $v->errors()->add('rate_pct_per_week', $reason);
            }
            if ($d['phase'] === 'cut' && ($reason = GoalRates::cutBlockedReason((float) $d['weight_kg'], (float) $d['height_cm'], isset($d['body_fat_pct']) ? (float) $d['body_fat_pct'] : null))) {
                $v->errors()->add('phase', $reason);
            }
        }];
    }
}
