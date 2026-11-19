<?php

namespace App\Http\Controllers;

use App\Domain\Foods\FoodSearch;
use App\Domain\Foods\MealMath;
use App\Models\Food;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Your own meals and recipes (BLUEPRINT 5.5), and meal-prep batches. Editing a meal only affects FUTURE logs: logs keep their snapshot. */
class MealController extends Controller
{
    public const TAGS = ['breakfast', 'pre', 'post', 'prep', 'snack'];

    public function index(Request $request): Response
    {
        return Inertia::render('Meals', ['meals' => $this->all($request->user()->id)]);
    }

    /** GET /meals/list: the same payload as JSON, for the Add sheet. */
    public function list(Request $request): JsonResponse
    {
        return response()->json(['meals' => $this->all($request->user()->id)]);
    }

    public function store(Request $request, FoodSearch $search): JsonResponse
    {
        $d = $this->validated($request);
        $id = DB::transaction(function () use ($request, $d) {
            $id = DB::table('meals')->insertGetId([
                'user_id' => $request->user()->id, 'name' => trim($d['name']), 'yield_portions' => $d['yield_portions'],
                'cook_loss' => $d['cook_loss'], 'tags' => json_encode(array_values($d['tags'] ?? [])), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->saveItems($id, $d['items'], $request->user()->id);

            return $id;
        });

        return response()->json(['meal' => $this->one($id, $request->user()->id)], 201);
    }

    public function update(Request $request, int $meal): JsonResponse
    {
        $this->own($request, $meal);
        $d = $this->validated($request);
        DB::transaction(function () use ($request, $meal, $d) {
            DB::table('meals')->where('id', $meal)->update([
                'name' => trim($d['name']), 'yield_portions' => $d['yield_portions'], 'cook_loss' => $d['cook_loss'],
                'tags' => json_encode(array_values($d['tags'] ?? [])), 'updated_at' => now(),
            ]);
            DB::table('meal_items')->where('meal_id', $meal)->delete();
            $this->saveItems($meal, $d['items'], $request->user()->id);
        });

        return response()->json(['meal' => $this->one($meal, $request->user()->id)]);
    }

    public function destroy(Request $request, int $meal): JsonResponse
    {
        $this->own($request, $meal);
        DB::table('meals')->where('id', $meal)->delete();   // food_logs.meal_id becomes null; their snapshots stay

        return response()->json(['deleted' => $meal]);
    }

    public function storeBatch(Request $request, int $meal): JsonResponse
    {
        $this->own($request, $meal);
        $d = $request->validate(['portions' => ['required', 'numeric', 'min:0.5', 'max:200']]);
        DB::table('meal_batches')->insert([
            'user_id' => $request->user()->id, 'meal_id' => $meal, 'portions' => $d['portions'],
            'made_on' => now()->setTimezone($request->user()->profile?->tz ?? config('fuel.default_timezone'))->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['meal' => $this->one($meal, $request->user()->id)], 201);
    }

    public function destroyBatch(Request $request, int $batch): JsonResponse
    {
        $row = DB::table('meal_batches')->where('id', $batch)->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        DB::table('meal_batches')->where('id', $batch)->delete();   // logs keep their snapshot; batch_id becomes null

        return response()->json(['meal' => $this->one((int) $row->meal_id, $request->user()->id)]);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'yield_portions' => ['required', 'numeric', 'min:0.5', 'max:200'],
            'cook_loss' => ['required', 'numeric', 'min:0', 'max:0.9'],
            'tags' => ['nullable', 'array', 'max:5'], 'tags.*' => [Rule::in(self::TAGS)],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.food_id' => ['required', 'integer', 'exists:foods,id'],
            'items.*.grams' => ['required', 'numeric', 'min:0.1', 'max:50000'],
        ]);
    }

    private function saveItems(int $mealId, array $items, int $userId): void
    {
        foreach (array_values($items) as $i => $it) {
            $food = Food::findOrFail($it['food_id']);
            abort_unless($food->visibleTo($userId), 404);
            DB::table('meal_items')->insert(['meal_id' => $mealId, 'food_id' => $food->id, 'grams' => $it['grams'], 'sort' => $i,
                'name' => trim(($food->brand && ! str_contains(mb_strtolower($food->name), mb_strtolower($food->brand)) ? $food->brand . ' ' : '') . $food->name)]);
        }
    }

    private function own(Request $request, int $meal): void
    {
        abort_unless(DB::table('meals')->where('id', $meal)->where('user_id', $request->user()->id)->exists(), 404);
    }

    /** @return list<array<string,mixed>> */
    private function all(int $userId): array
    {
        $ids = DB::table('meals')->where('user_id', $userId)->orderBy('name')->pluck('id')->all();

        return array_map(fn ($id) => $this->one((int) $id, $userId), $ids);
    }

    /** @return array<string,mixed> */
    public function one(int $id, int $userId): array
    {
        $m = DB::table('meals')->where('id', $id)->first();
        $items = DB::table('meal_items')->leftJoin('foods', 'foods.id', '=', 'meal_items.food_id')->where('meal_id', $id)->orderBy('sort')
            ->get(['meal_items.food_id', 'meal_items.name', 'meal_items.grams', 'foods.is_liquid', 'foods.per100_kcal', 'foods.per100_protein', 'foods.per100_carbs', 'foods.per100_fat', 'foods.per100_fibre']);

        $rows = $items->map(fn ($r) => [
            'food_id' => $r->food_id ? (int) $r->food_id : null, 'name' => $r->name, 'grams' => (float) $r->grams, 'unit' => $r->is_liquid ? 'mL' : 'g',
            'per100' => $r->food_id && $r->per100_kcal !== null ? [
                'kcal' => (float) $r->per100_kcal, 'protein' => $r->per100_protein !== null ? (float) $r->per100_protein : null,
                'carbs' => $r->per100_carbs !== null ? (float) $r->per100_carbs : null, 'fat' => $r->per100_fat !== null ? (float) $r->per100_fat : null,
                'fibre' => $r->per100_fibre !== null ? (float) $r->per100_fibre : null,
            ] : null,
        ])->all();

        $calc = MealMath::compute(array_map(fn ($r) => ['per100' => $r['per100'], 'grams' => $r['grams']], $rows), (float) $m->yield_portions, (float) $m->cook_loss);

        $batches = DB::table('meal_batches')->where('meal_id', $id)->orderByDesc('id')->get()->map(function ($b) {
            $logged = (float) DB::table('food_logs')->where('batch_id', $b->id)->sum('portions');

            return ['id' => (int) $b->id, 'portions' => (float) $b->portions, 'made_on' => $b->made_on, 'remaining' => MealMath::remaining((float) $b->portions, $logged)];
        })->all();

        return [
            'id' => (int) $m->id, 'name' => $m->name, 'yield_portions' => (float) $m->yield_portions, 'cook_loss' => (float) $m->cook_loss,
            'tags' => json_decode($m->tags, true) ?: [], 'items' => $rows, 'computed' => $calc, 'batches' => $batches,
        ];
    }
}
