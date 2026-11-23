<?php

namespace App\Services\Push;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Sends notifications to a person's subscribed devices, remembers what was sent, and drops devices that have gone. */
class PushService
{
    public function __construct(private PushTransport $transport) {}

    public static function configured(): bool
    {
        return (string) config('fuel.push.public_key') !== '' && (string) config('fuel.push.private_key') !== '';
    }

    /** @return array{session_fuel:bool,checkin:bool} */
    public function prefs(User $user): array
    {
        $p = $user->profile?->push_prefs;
        $p = is_string($p) ? json_decode($p, true) : $p;

        return ['session_fuel' => (bool) ($p['session_fuel'] ?? true), 'checkin' => (bool) ($p['checkin'] ?? true)];
    }

    public function subscribed(User $user): bool
    {
        return DB::table('push_subscriptions')->where('user_id', $user->id)->exists();
    }

    /**
     * Send once per dedupe key. Returns how many devices accepted it (0 when already sent, not configured, or nothing subscribed).
     * The key is recorded BEFORE sending, so two overlapping scheduler runs cannot both send.
     */
    public function send(User $user, string $dedupeKey, string $title, string $body, string $url = '/', string $tag = 'fuel'): int
    {
        if (! self::configured()) {
            return 0;
        }
        $subs = DB::table('push_subscriptions')->where('user_id', $user->id)->get();
        if ($subs->isEmpty()) {
            return 0;
        }
        if ($dedupeKey !== '' && ! DB::table('push_log')->insertOrIgnore(['user_id' => $user->id, 'dedupe_key' => $dedupeKey, 'sent_at' => now()])) {
            return 0;
        }

        return $this->deliver($subs, ['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag]);
    }

    /** A test message: not de-duplicated, so the button always does something. */
    public function test(User $user): int
    {
        if (! self::configured()) {
            return 0;
        }

        return $this->deliver(DB::table('push_subscriptions')->where('user_id', $user->id)->get(), ['title' => 'FUEL', 'body' => 'Notifications are working.', 'url' => '/settings/notifications', 'tag' => 'fuel-test']);
    }

    private function deliver($subs, array $payload): int
    {
        $ok = 0;
        foreach ($subs as $s) {
            try {
                $r = $this->transport->send(['endpoint' => $s->endpoint, 'p256dh' => $s->p256dh, 'auth' => $s->auth], json_encode($payload));
            } catch (\Throwable $e) {
                report($e);

                continue;
            }
            if ($r['gone']) {
                DB::table('push_subscriptions')->where('id', $s->id)->delete();
            }
            $ok += $r['ok'] ? 1 : 0;
        }

        return $ok;
    }
}
