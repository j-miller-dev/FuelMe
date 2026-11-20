<?php

namespace App\Http\Controllers;

use App\Models\FoodLog;
use App\Services\Capture\CaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Say or type a meal (BLUEPRINT 6). The review screen logs through the normal POST /logs, so every number is recomputed there. */
class CaptureController extends Controller
{
    public function parse(Request $request, CaptureService $capture): JsonResponse
    {
        $d = $request->validate(['text' => ['required', 'string', 'min:2', 'max:' . config('fuel.capture.max_chars')], 'meal_slot' => ['nullable', Rule::in(FoodLog::SLOTS)]]);
        $tz = $request->user()->profile?->tz ?? config('fuel.default_timezone');

        return response()->json($capture->run($request->user(), $d['text'], $d['meal_slot'] ?? null, now($tz)->format('l H:i')));
    }

    /** POST /capture/learn: the items you confirmed become personal aliases (phrase -> food + usual grams). */
    public function learn(Request $request, CaptureService $capture): JsonResponse
    {
        $d = $request->validate(['items' => ['required', 'array', 'max:40'], 'items.*.phrase' => ['required', 'string', 'max:120'],
            'items.*.food_id' => ['required', 'integer', 'exists:foods,id'], 'items.*.grams' => ['nullable', 'numeric', 'min:0.1', 'max:5000']]);

        return response()->json(['learned' => $capture->learn($request->user()->id, $d['items'])]);
    }
}
