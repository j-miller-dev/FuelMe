<?php

namespace App\Http\Controllers;

use App\Services\RecipeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Import recipes from notes (for example an Obsidian vault). Preview only: the review screen saves through the normal POST /meals. */
class RecipeImportController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('RecipeImport');
    }

    public function preview(Request $request, RecipeImportService $import): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:40'],
            'files.*' => ['file', 'max:200', 'extensions:md,markdown,txt'],   // KB; a recipe note is tiny
        ]);

        $files = [];
        foreach ($request->file('files') as $f) {
            $text = (string) file_get_contents($f->getRealPath());
            if (! mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            }
            $files[] = ['name' => $f->getClientOriginalName(), 'contents' => $text];
        }

        return response()->json($import->preview($request->user(), $files));
    }
}
