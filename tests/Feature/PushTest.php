<?php

use App\Console\Commands\NotifyCheckinCommand;
use App\Services\Push\PushService;
use App\Services\Push\PushTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FakePush implements PushTransport
{
    public static array $sent = [];
    public static bool $gone = false;

    public function send(array $sub, string $payload): array
    {
        self::$sent[] = [$sub['endpoint'], json_decode($payload, true)];

        return ['ok' => ! self::$gone, 'gone' => self::$gone];
    }
}

beforeEach(function () {
    FakePush::$sent = [];
    FakePush::$gone = false;
    config(['fuel.push.public_key' => 'pub-test-key', 'fuel.push.private_key' => 'priv-test-key']);
    app()->instance(PushTransport::class, new FakePush);
});
afterEach(fn () => Carbon::setTestNow());

function pushSub($test, $u, string $endpoint = 'https://push.example/abc'): void
{
    $test->actingAs($u)->postJson('/push/subscribe', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'p256-key', 'auth' => 'auth-key']])->assertOk();
}

it('says it is not set up when the server has no keys', function () {
    config(['fuel.push.public_key' => null, 'fuel.push.private_key' => null]);
    $u = loggedInUser();

    $this->actingAs($u)->get('/settings/notifications')->assertInertia(fn ($p) => $p->component('settings/Notifications')->where('configured', false)->where('publicKey', null));
    $this->actingAs($u)->postJson('/push/subscribe', ['endpoint' => 'https://push.example/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertStatus(503);
});

it('subscribes a device once, and unsubscribes it', function () {
    $u = loggedInUser();
    pushSub($this, $u);
    pushSub($this, $u);   // the same device again
    expect(DB::table('push_subscriptions')->count())->toBe(1);

    $this->actingAs($u)->postJson('/push/unsubscribe', ['endpoint' => 'https://push.example/abc'])->assertOk();
    expect(DB::table('push_subscriptions')->count())->toBe(0);
    $this->actingAs($u)->postJson('/push/subscribe', ['endpoint' => 'not a url', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertStatus(422);
});

it('sends a test, and removes a device the push service says is gone', function () {
    $u = loggedInUser();
    pushSub($this, $u);

    $this->actingAs($u)->postJson('/push/test')->assertOk()->assertJson(['sent' => 1]);
    expect(FakePush::$sent[0][1]['body'])->toBe('Notifications are working.');

    FakePush::$gone = true;
    $this->actingAs($u)->postJson('/push/test')->assertOk()->assertJson(['sent' => 0]);
    expect(DB::table('push_subscriptions')->count())->toBe(0);
});

it('never sends the same reminder twice, and keeps one person\'s devices apart', function () {
    $u = loggedInUser();
    pushSub($this, $u);
    $other = \App\Models\User::factory()->create();
    $svc = app(PushService::class);

    expect($svc->send($u, 'k1', 'T', 'B'))->toBe(1)->and($svc->send($u, 'k1', 'T', 'B'))->toBe(0)->and($svc->send($u, 'k2', 'T', 'B'))->toBe(1);
    expect($svc->send($other, 'k1', 'T', 'B'))->toBe(0)->and(count(FakePush::$sent))->toBe(2);   // $other has no device
});

it('saves which reminders you want', function () {
    $u = loggedInUser();
    expect(app(PushService::class)->prefs($u))->toBe(['session_fuel' => true, 'checkin' => true]);

    $this->actingAs($u)->put('/push/prefs', ['session_fuel' => false, 'checkin' => true])->assertRedirect();
    expect(app(PushService::class)->prefs($u->fresh()))->toBe(['session_fuel' => false, 'checkin' => true]);
});

it('sends the pre-session meal reminder once, at the right time, and not when switched off', function () {
    $u = loggedInUser();
    pushSub($this, $u);
    // The seed week has a Monday 12:00 lifting session (after a 06:30 BJJ); its meal is 3 h earlier, 09:00 local.
    $tz = $u->profile->tz;
    Carbon::setTestNow(Carbon::parse('2026-11-23 09:05', $tz));

    $this->artisan('notify:session-fuel')->assertSuccessful();
    $this->artisan('notify:session-fuel')->assertSuccessful();   // the next tick must not repeat it

    expect(count(FakePush::$sent))->toBe(1)->and(FakePush::$sent[0][1]['title'])->toBe('Pre-session meal');

    FakePush::$sent = [];
    DB::table('push_log')->delete();
    $u->profile()->update(['push_prefs' => ['session_fuel' => false, 'checkin' => true]]);
    $this->artisan('notify:session-fuel')->assertSuccessful();
    expect(FakePush::$sent)->toBe([]);

    $u->profile()->update(['push_prefs' => null]);
    Carbon::setTestNow(Carbon::parse('2026-11-23 10:30', $tz));   // an hour late: too late to tell anyone to eat now
    $this->artisan('notify:session-fuel')->assertSuccessful();
    expect(FakePush::$sent)->toBe([]);
});

it('does nothing when push is not configured', function () {
    config(['fuel.push.public_key' => null]);
    $u = loggedInUser();
    DB::table('push_subscriptions')->insert(['user_id' => $u->id, 'endpoint_hash' => str_repeat('a', 64), 'endpoint' => 'https://push.example/x', 'p256dh' => 'a', 'auth' => 'b', 'created_at' => now(), 'updated_at' => now()]);
    Carbon::setTestNow(Carbon::parse('2026-11-23 09:20', $u->profile->tz));

    $this->artisan('notify:session-fuel')->assertSuccessful();
    $this->artisan('notify:checkin')->assertSuccessful();

    expect(FakePush::$sent)->toBe([]);
});

it('tells you the check-in is ready on Sunday evening, once, and only when there is a proposal', function () {
    $u = loggedInUser();
    pushSub($this, $u);
    $tz = $u->profile->tz;
    $state = new \stdClass;
    $state->status = 'ready';
    $this->mock(\App\Services\ProgressService::class, fn ($m) => $m->shouldReceive('overview')->andReturnUsing(fn () => ['checkin' => ['status' => $state->status]]));

    Carbon::setTestNow(Carbon::parse('2026-11-29 19:00', $tz));   // a Sunday
    $this->artisan('notify:checkin')->assertSuccessful();
    $this->artisan('notify:checkin')->assertSuccessful();
    expect(count(FakePush::$sent))->toBe(1)->and(FakePush::$sent[0][1]['title'])->toBe('Weekly check-in ready')->and(FakePush::$sent[0][1]['body'])->not->toMatch('/\d{4}/');   // no target on the lock screen

    FakePush::$sent = [];
    DB::table('push_log')->delete();
    $state->status = 'hold';
    $this->artisan('notify:checkin')->assertSuccessful();
    expect(FakePush::$sent)->toBe([]);

    $state->status = 'ready';
    Carbon::setTestNow(Carbon::parse('2026-11-30 19:00', $tz));   // Monday
    $this->artisan('notify:checkin')->assertSuccessful();
    expect(FakePush::$sent)->toBe([]);
});
