<?php

namespace App\Http\Controllers;

use App\Domain\Foods\FoodSearch;
use App\Domain\Foods\QuickFoods;
use App\Models\Food;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoodSearchController extends Controller
{
    /** GET /foods/search?q=150g chicken breast -> { query: {term, qty, unit, variants}, results: [...] } */
    public function index(Request $request, FoodSearch $search): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($q) > 120, 422, 'Query too long.');
        $userId = $request->user()->id;

        if ($q === '') {
            return response()->json(['query' => ['term' => '', 'qty' => null, 'unit' => null, 'variants' => []], 'results' => []]);
        }

        return response()->json($search->search($q, $userId));
    }

    /** GET /foods/quick -> { frequent, recents, favourites } for the empty Add search. */
    public function quick(Request $request, QuickFoods $quick): JsonResponse
    {
        $tz = $request->user()->profile?->tz ?? config('fuel.default_timezone');

        return response()->json($quick->for($request->user()->id, now(), $tz));
    }

    /** POST /foods/{food}/favourite toggles a favourite. */
    public function favourite(Request $request, Food $food): JsonResponse
    {
        $uid = $request->user()->id;
        abort_unless($food->visibleTo($uid), 404);
        $q = \Illuminate\Support\Facades\DB::table('food_favourites')->where(['user_id' => $uid, 'food_id' => $food->id]);
        if ($q->exists()) {
            $q->delete();

            return response()->json(['favourite' => false]);
        }
        \Illuminate\Support\Facades\DB::table('food_favourites')->insert(['user_id' => $uid, 'food_id' => $food->id, 'created_at' => now()]);

        return response()->json(['favourite' => true]);
    }
}
