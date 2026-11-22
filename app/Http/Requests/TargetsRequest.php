<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Your own adjustments on top of the recommendation. Blank means "use the recommendation". */
class TargetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Wide on purpose: it is your call. The planner still caps protein at 35% of calories and says so.
            'protein_g_override' => ['nullable', 'integer', 'between:40,400'],
            'fat_min_g_per_kg' => ['nullable', 'numeric', 'between:0.3,1.5'],
            'kcal_offset' => ['nullable', 'integer', 'between:-1500,1500'],
            'hit_rules' => ['nullable', 'array'],
            'hit_rules.kcal_pct' => ['nullable', 'numeric', 'between:1,30'],
            'hit_rules.protein_min_pct' => ['nullable', 'numeric', 'between:50,100'],
            'hit_rules.carbs_pct' => ['nullable', 'numeric', 'between:1,50'],
            'hit_rules.fat_pct' => ['nullable', 'numeric', 'between:1,50'],
        ];
    }
}
