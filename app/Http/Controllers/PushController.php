<?php

namespace App\Http\Controllers;

use App\Services\Push\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Phone notifications: turn them on for this device, choose which ones, and send a test. Messages stay generic because they show on a lock screen. */
class PushController extends Controller
{
    public function show(Request $request, PushService $push): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Notifications', [
            'configured' => PushService::configured(),
            'publicKey' => PushService::configured() ? (string) config('fuel.push.public_key') : null,
            'devices' => DB::table('push_subscriptions')->where('user_id', $user->id)->count(),
            'prefs' => $push->prefs($user),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        abort_unless(PushService::configured(), 503);
        $d = $request->validate(['endpoint' => ['required', 'url', 'max:2000'], 'keys.p256dh' => ['required', 'string', 'max:200'], 'keys.auth' => ['required', 'string', 'max:100']]);
        DB::table('push_subscriptions')->updateOrInsert(['endpoint_hash' => hash('sha256', $d['endpoint'])], ['user_id' => $request->user()->id, 'endpoint' => $d['endpoint'], 'p256dh' => $d['keys']['p256dh'], 'auth' => $d['keys']['auth'],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 160), 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $d = $request->validate(['endpoint' => ['required', 'url', 'max:2000']]);
        DB::table('push_subscriptions')->where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $d['endpoint']))->delete();

        return response()->json(['ok' => true]);
    }

    public function prefs(Request $request): RedirectResponse
    {
        $d = $request->validate(['session_fuel' => ['required', 'boolean'], 'checkin' => ['required', 'boolean']]);
        $request->user()->profile()->update(['push_prefs' => ['session_fuel' => (bool) $d['session_fuel'], 'checkin' => (bool) $d['checkin']]]);

        return back();
    }

    public function test(Request $request, PushService $push): JsonResponse
    {
        abort_unless(PushService::configured(), 503);

        return response()->json(['sent' => $push->test($request->user())]);
    }
}
