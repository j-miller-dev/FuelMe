<?php

namespace App\Http\Controllers;

use App\Domain\Coach\PrincipleParser;
use App\Services\Coach\PrincipleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** /principles: your notes as principle cards, and which ones you follow. Notes come in from Obsidian; nothing is written back. */
class PrincipleController extends Controller
{
    public function show(Request $request, PrincipleService $svc): Response
    {
        $user = $request->user();

        return Inertia::render('Principles', ['cards' => $svc->cards($user), 'stance' => $user->profile?->coach_stance ?? '', 'maxChars' => PrincipleParser::MAX_CHARS]);
    }

    public function import(Request $request, PrincipleService $svc): JsonResponse
    {
        $request->validate(['files' => ['required', 'array', 'min:1', 'max:300'], 'files.*' => ['file', 'max:50', 'extensions:md,markdown,txt'], 'full_sync' => ['nullable', 'boolean']]);
        $files = [];
        foreach ($request->file('files') as $f) {
            $text = (string) file_get_contents($f->getRealPath());
            if (! mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            }
            $files[] = ['name' => $f->getClientOriginalName(), 'contents' => $text];
        }

        return response()->json($svc->import($request->user(), $files, $request->boolean('full_sync')));
    }

    public function stance(Request $request, PrincipleService $svc, int $principle): RedirectResponse
    {
        $d = $request->validate(['stance' => ['nullable', 'in:follow,context,ignore']]);
        abort_unless($svc->setStance($request->user(), $principle, $d['stance'] ?? null), 404);

        return back();
    }

    public function archive(Request $request, PrincipleService $svc, int $principle): RedirectResponse
    {
        abort_unless($svc->archive($request->user(), $principle), 404);

        return back();
    }

    public function saveStance(Request $request): RedirectResponse
    {
        $d = $request->validate(['text' => ['nullable', 'string', 'max:600']]);
        $request->user()->profile()->update(['coach_stance' => trim((string) ($d['text'] ?? '')) ?: null]);

        return back();
    }
}
