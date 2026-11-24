<?php

namespace App\Http\Controllers;

use App\Services\AchievementsService;
use Illuminate\Http\Request;

/** Swiping a win away on Today. Remembers the keys so the same win does not come back; a new win has a new key. */
class AchievementController extends Controller
{
    public function dismiss(Request $request, AchievementsService $svc)
    {
        $d = $request->validate(['keys' => ['required', 'array', 'min:1', 'max:20'], 'keys.*' => ['required', 'string', 'max:100']]);
        $svc->dismiss($request->user(), $d['keys']);

        return response()->json(['ok' => true]);
    }
}
