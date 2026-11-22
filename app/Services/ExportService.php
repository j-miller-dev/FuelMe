<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Your data, in formats you can read anywhere (BLUEPRINT principle 5: it is yours). No passwords, passkeys or tokens are ever included. */
class ExportService
{
    public const VERSION = 1;

    /** @return array<string,mixed> */
    public function backup(User $user): array
    {
        $own = fn (string $table) => DB::table($table)->where('user_id', $user->id);
        $strip = fn (array $rows, array $drop = ['user_id']) => array_map(fn ($r) => array_diff_key((array) $r, array_flip($drop)), $rows);

        $mealIds = $own('meals')->pluck('id')->all();
        $foodIds = $own('foods')->where('source', 'user')->pluck('id')->all();
        $lists = $own('shopping_lists')->get();

        return [
            'app' => 'FUEL', 'version' => self::VERSION, 'exported_at' => now()->toIso8601String(),
            'profile' => $strip($own('profiles')->get()->all(), ['user_id', 'created_at', 'updated_at'])[0] ?? null,
            'goals' => $strip($own('goals')->orderBy('id')->get()->all()),
            'target_history' => array_map(fn ($r) => ['plan_json' => json_decode($r['plan_json'], true)] + $r, $strip($own('target_history')->orderBy('id')->get()->all())),
            'weights' => $strip($own('weight_logs')->orderBy('local_date')->get(['local_date', 'kg', 'source'])->all()),
            'food_logs' => $strip($own('food_logs')->orderBy('local_date')->orderBy('id')->get(['local_date', 'logged_at', 'meal_slot', 'name', 'grams', 'serving_label', 'kcal', 'protein', 'carbs', 'fat', 'fibre', 'source'])->all()),
            'workouts' => $strip($own('workout_logs')->orderBy('local_date')->orderBy('id')->get(['local_date', 'status', 'type', 'intensity', 'minutes', 'rpe', 'met', 'net_kcal', 'kcal_override', 'start_time', 'name', 'source'])->all()),
            'training_template' => $strip($own('training_templates')->orderBy('id')->get(['day', 'start_time', 'type', 'intensity', 'minutes', 'custom_met', 'name'])->all()),
            'foods' => array_map(fn ($f) => ['servings' => DB::table('food_servings')->where('food_id', $f->id)->get(['label', 'grams'])->map(fn ($s) => (array) $s)->all()] + array_diff_key((array) $f, ['id' => 1, 'user_id' => 1, 'tsv' => 1, 'name_norm' => 1]),
                $foodIds ? DB::table('foods')->whereIn('id', $foodIds)->get()->all() : []),
            'meals' => array_map(fn ($m) => ['tags' => json_decode($m->tags, true), 'items' => DB::table('meal_items')->where('meal_id', $m->id)->orderBy('sort')->get(['name', 'grams'])->map(fn ($i) => (array) $i)->all()]
                + array_diff_key((array) $m, ['id' => 1, 'user_id' => 1, 'tags' => 1]), $mealIds ? DB::table('meals')->whereIn('id', $mealIds)->get()->all() : []),
            'aliases' => DB::table('food_aliases')->join('foods', 'foods.id', '=', 'food_aliases.food_id')->where('food_aliases.user_id', $user->id)->get(['food_aliases.phrase', 'foods.name as food', 'food_aliases.default_grams', 'food_aliases.hits'])->map(fn ($r) => (array) $r)->all(),
            'pantry' => $strip($own('pantry_items')->get(['ingredient_key', 'label', 'qty', 'unit', 'is_staple'])->all()),
            'shopping_lists' => $lists->map(fn ($l) => ['name' => $l->name, 'status' => $l->status, 'items' => DB::table('shopping_list_items')->where('list_id', $l->id)->get(['label', 'qty', 'unit', 'aisle', 'state'])->map(fn ($i) => (array) $i)->all()])->all(),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function foodLogsCsv(User $user): \Generator
    {
        yield ['date', 'logged_at', 'meal', 'name', 'grams', 'serving', 'kcal', 'protein_g', 'carbs_g', 'fat_g', 'fibre_g', 'source'];
        foreach (DB::table('food_logs')->where('user_id', $user->id)->orderBy('local_date')->orderBy('id')->cursor() as $r) {
            yield [$r->local_date, $r->logged_at, $r->meal_slot, $r->name, $r->grams, $r->serving_label, $r->kcal, $r->protein, $r->carbs, $r->fat, $r->fibre, $r->source];
        }
    }

    public function weightsCsv(User $user): \Generator
    {
        yield ['date', 'kg', 'source'];
        foreach (DB::table('weight_logs')->where('user_id', $user->id)->orderBy('local_date')->cursor() as $r) {
            yield [$r->local_date, $r->kg, $r->source];
        }
    }

    public function workoutsCsv(User $user): \Generator
    {
        yield ['date', 'status', 'activity', 'intensity', 'minutes', 'met', 'net_kcal_estimate', 'kcal_override', 'start_time', 'name'];
        foreach (DB::table('workout_logs')->where('user_id', $user->id)->orderBy('local_date')->orderBy('id')->cursor() as $r) {
            yield [$r->local_date, $r->status, $r->type, $r->intensity, $r->minutes, $r->met, $r->net_kcal, $r->kcal_override, $r->start_time, $r->name];
        }
    }

    /** A spreadsheet cell that starts with = + - or @ is run as a formula by Excel and Sheets. Prefix a quote so food names can never do that. */
    public static function safeCell(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
    }
}
