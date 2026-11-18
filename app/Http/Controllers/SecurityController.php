<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('settings/Security', [
            'passkeys' => $request->user()->passkeys()->latest()->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'createdAt' => $p->created_at?->toIso8601String(),
                'lastUsedAt' => $p->last_used_at?->toIso8601String(),
            ]),
        ]);
    }
}
