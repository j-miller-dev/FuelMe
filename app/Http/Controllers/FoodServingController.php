<?php

namespace App\Http\Controllers;

use App\Models\Food;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Your own household measures for a food, e.g. "1 egg" = 50 g. Never touches the shared food row. */
class FoodServingController extends Controller
{
    public function store(Request $request, Food $food): JsonResponse
    {
        $d = $request->validate([
            'label' => ['required', 'string', 'min:1', 'max:60'],
            'grams' => ['required', 'numeric', 'between:0.5,5000'],
        ]);
        // Visible to you if the food is shared (AFCD/OFF) or one of yours; never someone else's custom food.
        abort_unless($food->user_id === null || $food->user_id === $request->user()->id, 404);

        DB::table('user_food_servings')->updateOrInsert(
            ['user_id' => $request->user()->id, 'food_id' => $food->id, 'label' => trim($d['label'])],
            ['grams' => round((float) $d['grams'], 2), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['servings' => $this->list($request->user()->id, $food->id)]);
    }

    public function destroy(Request $request, int $serving): JsonResponse
    {
        $row = DB::table('user_food_servings')->where('id', $serving)->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        DB::table('user_food_servings')->where('id', $serving)->delete();

        return response()->json(['servings' => $this->list($request->user()->id, (int) $row->food_id)]);
    }

    /** @return list<array{id?:int,label:string,grams:float,own?:bool}> your measures first, then the food's own */
    private function list(int $userId, int $foodId): array
    {
        $own = DB::table('user_food_servings')->where('user_id', $userId)->where('food_id', $foodId)->orderBy('id')->get()
            ->map(fn ($s) => ['id' => (int) $s->id, 'label' => $s->label, 'grams' => (float) $s->grams, 'own' => true]);
        $shared = DB::table('food_servings')->where('food_id', $foodId)->orderBy('id')->get()
            ->map(fn ($s) => ['label' => $s->label, 'grams' => (float) $s->grams]);

        return $own->concat($shared->reject(fn ($s) => $own->contains('label', $s['label'])))->values()->all();
    }
}
