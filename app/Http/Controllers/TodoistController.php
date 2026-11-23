<?php

namespace App\Http\Controllers;

use App\Domain\Shopping\ShoppingStore;
use App\Services\Todoist\TodoistClient;
use App\Services\Todoist\TodoistException;
use App\Services\Todoist\TodoistExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Send a shopping list to Todoist (add-only). The token is stored encrypted and never sent back to the browser. */
class TodoistController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('settings/Todoist', ['connected' => DB::table('todoist_connections')->where('user_id', $request->user()->id)->exists()]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $d = $request->validate(['token' => ['required', 'string', 'min:20', 'max:200']]);
        $token = trim($d['token']);
        try {
            (new TodoistClient($token))->check();
        } catch (TodoistException $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }
        DB::table('todoist_connections')->updateOrInsert(['user_id' => $request->user()->id], ['api_token' => Crypt::encryptString($token), 'project_id' => null, 'updated_at' => now(), 'created_at' => now()]);

        return back();
    }

    public function disconnect(Request $request): RedirectResponse
    {
        DB::table('todoist_exports')->where('user_id', $request->user()->id)->delete();
        DB::table('todoist_connections')->where('user_id', $request->user()->id)->delete();

        return back();
    }

    public function export(Request $request, int $list, ShoppingStore $store, TodoistExport $export): JsonResponse
    {
        $store->own($request->user()->id, $list);   // 404 unless it is your list

        return response()->json($export->export($request->user(), $list));
    }
}
