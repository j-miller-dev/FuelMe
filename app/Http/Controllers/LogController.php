<?php

namespace App\Http\Controllers;

use App\Domain\Foods\LocalDay;
use App\Domain\Foods\MealMath;
use App\Domain\Foods\Portion;
use App\Models\Food;
use App\Models\FoodLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

class LogController extends Controller
{
    /**
     * POST /logs: log one or more foods. Macros for a food are computed HERE from the food row (never trusted from the client);
     * only a quick-add carries raw macros. Retried requests are safe: each item has an idempotency key.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'local_date' => ['nullable', 'date_format:Y-m-d', 'after:-400 days', 'before:+2 days'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.idempotency_key' => ['required', 'uuid'],
            'items.*.meal_slot' => ['required', Rule::in(FoodLog::SLOTS)],
            'items.*.source' => ['required', Rule::in(['search', 'quick', 'meal', 'capture'])],
            'items.*.food_id' => ['nullable', 'integer', 'exists:foods,id'],
            'items.*.meal_id' => ['nullable', 'integer', 'exists:meals,id'],
            'items.*.batch_id' => ['nullable', 'integer', 'exists:meal_batches,id'],
            'items.*.portions' => ['nullable', 'numeric', 'min:0.05', 'max:50'],
            'items.*.grams' => ['nullable', 'numeric', 'min:0.1', 'max:5000'],
            'items.*.serving_label' => ['nullable', 'string', 'max:80'],
            'items.*.name' => ['nullable', 'string', 'max:200'],
            'items.*.kcal' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'items.*.protein' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'items.*.carbs' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'items.*.fat' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        $tz = $user->profile?->tz ?? config('fuel.default_timezone');
        $rollover = $user->profile?->rollover_hour ?? config('fuel.rollover_hour');
        $date = $data['local_date'] ?? LocalDay::of(now(), $tz, $rollover);

        $created = DB::transaction(function () use ($data, $user, $date) {
            $out = [];
            foreach ($data['items'] as $i => $it) {
                if ($existing = FoodLog::where('idempotency_key', $it['idempotency_key'])->where('user_id', $user->id)->first()) {
                    $out[] = $existing;   // a retry: return what we already stored

                    continue;
                }
                $snap = ($it['source'] ?? '') === 'meal' ? $this->mealSnapshot($it, $user->id) : $this->snapshot($it, $user->id, $i);
                $out[] = FoodLog::create($snap + [
                    'user_id' => $user->id, 'local_date' => $date, 'logged_at' => now(), 'meal_slot' => $it['meal_slot'],
                    'source' => $it['source'], 'idempotency_key' => $it['idempotency_key'],
                ]);
                if (! empty($it['food_id'])) {
                    Food::whereKey($it['food_id'])->update(['use_count' => DB::raw('use_count + 1'), 'last_used_at' => now()]);
                }
            }

            return $out;
        });

        return response()->json(['logs' => array_map(fn ($l) => $l->toPayload(), $created)], 201);
    }

    /** PATCH /logs/{log}: change the weight and/or meal. A new weight scales the stored snapshot, so history is not rewritten. */
    public function update(Request $request, FoodLog $log): JsonResponse
    {
        abort_unless($log->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'grams' => ['nullable', 'numeric', 'min:0.1', 'max:5000'],
            'meal_slot' => ['nullable', Rule::in(FoodLog::SLOTS)],
            'serving_label' => ['nullable', 'string', 'max:80'],
        ]);

        $changes = [];
        if (isset($data['grams'])) {
            abort_if($log->grams === null, 422, 'A quick-add has no weight to change. Delete it and add it again.');
            $changes = Portion::scale(
                ['kcal' => $log->kcal, 'protein' => $log->protein, 'carbs' => $log->carbs, 'fat' => $log->fat, 'fibre' => $log->fibre],
                (float) $log->grams, (float) $data['grams'],
            ) + ['grams' => $data['grams'], 'serving_label' => $data['serving_label'] ?? null];
            if ($log->portions !== null) {   // a meal log keeps its portions in step, so a prep batch's remaining count stays right
                $changes['portions'] = round((float) $log->portions * (float) $data['grams'] / (float) $log->grams, 2);
                $changes['serving_label'] = null;
            }
        }
        if (isset($data['meal_slot'])) {
            $changes['meal_slot'] = $data['meal_slot'];
        }
        $log->update($changes);

        return response()->json(['log' => $log->fresh()->toPayload()]);
    }

    public function destroy(Request $request, FoodLog $log): JsonResponse
    {
        abort_unless($log->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($log) {
            if ($log->food_id) {
                // Undoing a mistaken add must not boost that food in future rankings.
                Food::whereKey($log->food_id)->where('use_count', '>', 0)->update(['use_count' => DB::raw('use_count - 1')]);
            }
            $log->delete();
        });

        return response()->json(['deleted' => $log->id]);
    }

    /**
     * POST /logs/copy: copy a whole day, or one meal of it, onto another day (default today). Snapshots are copied as they were.
     * A batch key makes a double tap harmless: every copied row gets a key derived from (batch key, source row).
     */
    public function copy(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after:-400 days', 'before:+2 days'],
            'meal_slot' => ['nullable', Rule::in(FoodLog::SLOTS)],
            'batch_key' => ['required', 'uuid'],
        ]);
        $tz = $user->profile?->tz ?? config('fuel.default_timezone');
        $to = $data['to_date'] ?? LocalDay::of(now(), $tz, $user->profile?->rollover_hour ?? config('fuel.rollover_hour'));
        abort_if($data['from_date'] === $to && empty($data['meal_slot']), 422, 'Pick a different day to copy from.');

        $source = FoodLog::where('user_id', $user->id)->where('local_date', $data['from_date'])
            ->when($data['meal_slot'] ?? null, fn ($q, $slot) => $q->where('meal_slot', $slot))->orderBy('id')->get();

        $created = DB::transaction(function () use ($source, $user, $to, $data) {
            $out = [];
            foreach ($source as $s) {
                $key = Uuid::uuid5($data['batch_key'], (string) $s->id)->toString();
                $out[] = FoodLog::where('idempotency_key', $key)->first() ?? FoodLog::create([
                    'user_id' => $user->id, 'local_date' => $to, 'logged_at' => now(), 'meal_slot' => $s->meal_slot, 'food_id' => $s->food_id,
                    'name' => $s->name, 'grams' => $s->grams, 'serving_label' => $s->serving_label, 'kcal' => $s->kcal, 'protein' => $s->protein,
                    'carbs' => $s->carbs, 'fat' => $s->fat, 'fibre' => $s->fibre, 'source' => 'copy', 'idempotency_key' => $key,
                ]);
            }

            return $out;
        });

        return response()->json(['logs' => array_map(fn ($l) => $l->toPayload(), $created)], 201);
    }

    /**
     * A logged meal: macros come from the recipe as it is NOW, then are frozen into the log. Give portions (1, 0.5, 1.5) or a weight
     * (250 g of a 400 g portion = 0.625). A batch, when given, is drawn down and cannot go below zero.
     *
     * @return array<string,mixed>
     */
    private function mealSnapshot(array $it, int $userId): array
    {
        abort_if(empty($it['meal_id']), 422, 'Pick a meal.');
        abort_unless(DB::table('meals')->where('id', $it['meal_id'])->where('user_id', $userId)->exists(), 404);
        $meal = app(MealController::class)->one((int) $it['meal_id'], $userId);
        $calc = $meal['computed'];
        abort_if($calc['incomplete'] > 0, 422, "{$meal['name']} has an ingredient with no energy data. Edit the meal first.");

        $portions = ! empty($it['portions']) ? (float) $it['portions']
            : (! empty($it['grams']) ? MealMath::portionsForGrams((float) $it['grams'], (float) $calc['portion']['grams']) : 1.0);
        abort_if($portions < 0.05 || $portions > 50, 422, 'That is not a sensible number of portions.');

        $batchId = null;
        if (! empty($it['batch_id'])) {
            $batch = collect($meal['batches'])->firstWhere('id', (int) $it['batch_id']);
            abort_unless($batch, 404);
            abort_if($portions > $batch['remaining'] + 0.0001, 422, 'Only ' . rtrim(rtrim(number_format($batch['remaining'], 2, '.', ''), '0'), '.') . ' portions left in that batch.');
            $batchId = (int) $it['batch_id'];
        }

        $label = ! empty($it['portions']) || empty($it['grams'])
            ? rtrim(rtrim(number_format($portions, 2, '.', ''), '0'), '.') . ($portions == 1.0 ? ' portion' : ' portions')
            : rtrim(rtrim(number_format((float) $it['grams'], 1, '.', ''), '0'), '.') . ' g';

        return [
            'food_id' => null, 'meal_id' => (int) $it['meal_id'], 'batch_id' => $batchId, 'portions' => round($portions, 2), 'name' => $meal['name'],
            'grams' => round($calc['portion']['grams'] * $portions, 2), 'serving_label' => $label,
            'kcal' => round($calc['portion']['kcal'] * $portions, 2), 'protein' => round($calc['portion']['protein'] * $portions, 2),
            'carbs' => round($calc['portion']['carbs'] * $portions, 2), 'fat' => round($calc['portion']['fat'] * $portions, 2),
            'fibre' => round($calc['portion']['fibre'] * $portions, 2),
        ];
    }

    /** @return array<string,mixed> the name/grams/macros columns for one item */
    private function snapshot(array $it, int $userId, int $index): array
    {
        if (empty($it['food_id'])) {
            // Quick-add: raw macros typed by the user.
            $p = (float) ($it['protein'] ?? 0);
            $c = (float) ($it['carbs'] ?? 0);
            $f = (float) ($it['fat'] ?? 0);
            $kcal = Portion::quickKcal(isset($it['kcal']) ? (float) $it['kcal'] : null, $p, $c, $f);
            abort_if($kcal <= 0, 422, 'Enter calories or at least one macro.');

            return ['food_id' => null, 'name' => trim($it['name'] ?? '') ?: 'Quick add', 'grams' => null, 'serving_label' => null,
                'kcal' => $kcal, 'protein' => $p, 'carbs' => $c, 'fat' => $f, 'fibre' => null];
        }

        $food = Food::findOrFail($it['food_id']);
        abort_unless($food->visibleTo($userId), 404);
        abort_if(empty($it['grams']), 422, 'Enter an amount.');
        abort_if($food->per100_kcal === null, 422, "{$food->name} has no energy data.");

        $m = Portion::macros([
            'kcal' => (float) $food->per100_kcal, 'protein' => $food->per100_protein !== null ? (float) $food->per100_protein : null,
            'carbs' => $food->per100_carbs !== null ? (float) $food->per100_carbs : null, 'fat' => $food->per100_fat !== null ? (float) $food->per100_fat : null,
            'fibre' => $food->per100_fibre !== null ? (float) $food->per100_fibre : null,
        ], (float) $it['grams']);

        return ['food_id' => $food->id, 'name' => trim(($food->brand && ! str_contains(mb_strtolower($food->name), mb_strtolower($food->brand)) ? $food->brand . ' ' : '') . $food->name),
            'grams' => (float) $it['grams'], 'serving_label' => $it['serving_label'] ?? null] + $m;
    }
}
