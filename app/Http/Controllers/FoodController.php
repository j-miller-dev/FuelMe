<?php

namespace App\Http\Controllers;

use App\Domain\Foods\FoodForm;
use App\Domain\Foods\FoodSearch;
use App\Domain\Foods\Gtin;
use App\Models\Food;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The user's own foods (BLUEPRINT 5.5): create from a label, fix, delete. History keeps its snapshots either way. */
class FoodController extends Controller
{
    public function index(Request $request, FoodSearch $search): Response
    {
        $ids = DB::table('foods')->where('user_id', $request->user()->id)->where('source', 'user')->orderBy('name')->pluck('id')->map(fn ($i) => (int) $i)->all();

        return Inertia::render('Foods', ['foods' => $search->byIds($ids, $request->user()->id)]);
    }

    public function store(Request $request, FoodSearch $search): JsonResponse
    {
        [$attrs, $servings, $warnings] = $this->parse($request, null);
        $id = DB::transaction(function () use ($request, $attrs, $servings) {
            $id = DB::table('foods')->insertGetId($attrs + ['user_id' => $request->user()->id, 'source' => 'user', 'source_id' => null,
                'state' => 'as_sold', 'quality' => 100, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($servings as $s) {
                DB::table('food_servings')->insert($s + ['food_id' => $id]);
            }

            return $id;
        });

        return response()->json(['food' => $search->byIds([$id], $request->user()->id)[0], 'warnings' => $warnings], 201);
    }

    public function update(Request $request, Food $food, FoodSearch $search): JsonResponse
    {
        $this->authorizeOwn($request, $food);
        [$attrs, $servings, $warnings] = $this->parse($request, $food);
        DB::transaction(function () use ($food, $attrs, $servings) {
            DB::table('foods')->where('id', $food->id)->update($attrs + ['updated_at' => now()]);
            DB::table('food_servings')->where('food_id', $food->id)->delete();
            foreach ($servings as $s) {
                DB::table('food_servings')->insert($s + ['food_id' => $food->id]);
            }
        });

        return response()->json(['food' => $search->byIds([$food->id], $request->user()->id)[0], 'warnings' => $warnings]);
    }

    public function destroy(Request $request, Food $food): JsonResponse
    {
        $this->authorizeOwn($request, $food);
        $food->delete();   // food_logs.food_id is set null; their name + macro snapshots stay

        return response()->json(['deleted' => $food->id]);
    }

    private function authorizeOwn(Request $request, Food $food): void
    {
        abort_unless($food->source === 'user' && $food->user_id === $request->user()->id, 404);
    }

    /** @return array{0:array<string,mixed>,1:list<array<string,mixed>>,2:list<string>} food columns, serving rows, warnings */
    private function parse(Request $request, ?Food $existing): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:200'], 'brand' => ['nullable', 'string', 'max:100'], 'store' => ['nullable', 'string', 'max:60'],
            'category' => ['nullable', 'string', 'max:160'], 'barcode' => ['nullable', 'string', 'max:40'], 'is_liquid' => ['boolean'],
            'basis' => ['required', 'in:per100,serving'], 'serving_label' => ['nullable', 'string', 'max:80'], 'serving_grams' => ['nullable', 'numeric', 'min:0.1', 'max:5000'],
            'kcal' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'protein' => ['required', 'numeric', 'min:0', 'max:100000'],
            'carbs' => ['required', 'numeric', 'min:0', 'max:100000'], 'fat' => ['required', 'numeric', 'min:0', 'max:100000'],
            'fibre' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'sugar' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'sat_fat' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'sodium_mg' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);

        try {
            $conv = FoodForm::toPer100($d);
        } catch (\InvalidArgumentException $e) {
            abort(response()->json(['message' => $e->getMessage(), 'errors' => ['nutrition' => [$e->getMessage()]]], 422));
        }

        $barcode = null;
        if (! empty($d['barcode'])) {
            $barcode = Gtin::clean($d['barcode']);
            if (! Gtin::valid($barcode)) {
                abort(response()->json(['message' => 'That barcode is not valid (check the digits).', 'errors' => ['barcode' => ['Invalid barcode.']]], 422));
            }
            $clash = DB::table('foods')->whereIn('barcode', Gtin::variants($barcode))->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->value('id');
            if ($clash) {
                abort(response()->json(['message' => 'This barcode is already in the database.', 'errors' => ['barcode' => ['Already exists.']], 'existing_food_id' => (int) $clash], 422));
            }
        }

        $servings = [];
        if (! empty($d['serving_grams'])) {
            $g = (float) $d['serving_grams'];
            $servings[] = ['label' => trim($d['serving_label'] ?? '') ?: '1 serving (' . rtrim(rtrim(number_format($g, 1, '.', ''), '0'), '.') . ' ' . (($d['is_liquid'] ?? false) ? 'mL' : 'g') . ')', 'grams' => $g];
        }

        $attrs = $conv['per100'] + [
            'name' => trim($d['name']), 'brand' => trim($d['brand'] ?? '') ?: null, 'barcode' => $barcode,
            'stores' => json_encode(array_values(array_filter([trim($d['store'] ?? '')]))), 'category' => trim($d['category'] ?? '') ?: null,
            'is_liquid' => (bool) ($d['is_liquid'] ?? false), 'needs_check' => $conv['needs_check'],
        ];

        return [$attrs, $servings, $conv['warnings']];
    }
}
