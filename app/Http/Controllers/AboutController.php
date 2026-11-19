<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AboutController extends Controller
{
    public function show(Request $request): Response
    {
        $by = fn (string $source) => DB::table('foods')->where('source', $source)->count();

        return Inertia::render('settings/About', [
            'counts' => ['afcd' => $by('afcd'), 'off' => $by('off'), 'user' => $by('user') + $by('recipe')],
        ]);
    }
}
