<?php

namespace App\Http\Controllers;

use App\Domain\Foods\FoodSearch;
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
            return response()->json(['query' => ['term' => '', 'qty' => null, 'unit' => null, 'variants' => []], 'results' => $search->recent($userId)]);
        }

        return response()->json($search->search($q, $userId));
    }
}
