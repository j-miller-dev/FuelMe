<?php

use App\Services\Coach\CoachClient;
use App\Services\Coach\CoachService;
use App\Services\Coach\CoachTools;
use Illuminate\Support\Facades\DB;

/** Scripted model: returns the queued responses in order and records what it was sent. */
class FakeCoachClient implements CoachClient
{
    public array $sent = [];

    public function __construct(public array $script) {}

    public function send(string $system, array $messages, array $tools): array
    {
        $this->sent[] = ['system' => $system, 'messages' => $messages, 'tools' => $tools];
        $next = array_shift($this->script);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next + ['stop_reason' => 'end_turn', 'input_tokens' => 10, 'output_tokens' => 5];
    }
}

function coachText(string $t): array { return ['content' => [['type' => 'text', 'text' => $t]]]; }
function coachUse(string $name, array $input = [], string $id = 'tu_1'): array { return ['content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]], 'stop_reason' => 'tool_use']; }
function coachFake(array $script): FakeCoachClient
{
    config(['fuel.coach.enabled' => true, 'fuel.capture.api_key' => 'test']);
    $f = new FakeCoachClient($script);
    app()->instance(CoachClient::class, $f);

    return $f;
}

it('is off without a key or the flag, and the page says so', function () {
    config(['fuel.coach.enabled' => true, 'fuel.capture.api_key' => null]);
    $u = loggedInUser();
    $this->actingAs($u)->get('/coach')->assertInertia(fn ($p) => $p->component('Coach')->where('enabled', false));
    $this->actingAs($u)->postJson('/coach/messages', ['text' => 'hello there'])->assertNotFound();
    config(['fuel.coach.enabled' => false, 'fuel.capture.api_key' => 'x']);
    $this->actingAs($u)->postJson('/coach/messages', ['text' => 'hello there'])->assertNotFound();
});

it('runs the tool loop and answers with numbers a tool returned', function () {
    $u = loggedInUser();
    $target = (int) round(app(CoachTools::class)->run('get_today_status', [], $u)['target']['kcal'] ?? 0);
    expect($target)->toBeGreaterThan(1000);
    $f = coachFake([coachUse('get_today_status'), coachText("Your target today is {$target} kcal.")]);

    $r = $this->actingAs($u)->postJson('/coach/messages', ['text' => 'What is my target today?'])->assertOk()->json();

    expect($r['message']['content'])->toContain((string) $target)->and($r['message']['grounded'])->toBeTrue()->and($r['message']['tools'])->toBe(['get_today_status']);
    expect($f->sent)->toHaveCount(2);
    $toolResultMsg = end($f->sent)['messages'];
    expect(end($toolResultMsg)['content'][0]['type'])->toBe('tool_result');
    expect(DB::table('coach_messages')->where('thread_id', $r['thread_id'])->count())->toBe(2);
});

it('retries once when a number is not in any tool result, then accepts a grounded rewrite', function () {
    $u = loggedInUser();
    $f = coachFake([coachUse('get_today_status'), coachText('Eat 98765 kcal.'), coachText('I would stick to the target the app shows you.')]);
    $r = $this->actingAs($u)->postJson('/coach/messages', ['text' => 'How much should I eat?'])->assertOk()->json();

    expect($r['message']['grounded'])->toBeTrue()->and($r['message']['content'])->not->toContain('98765');
    $last = end($f->sent)['messages'];
    expect(end($last)['content'])->toContain('98765');
});

it('replaces an answer that stays ungrounded after the retry with the safe message', function () {
    $u = loggedInUser();
    coachFake([coachUse('get_today_status'), coachText('Eat 98765 kcal.'), coachText('Really, eat 98765 kcal.')]);
    $r = $this->actingAs($u)->postJson('/coach/messages', ['text' => 'How much should I eat?'])->assertOk()->json();

    expect($r['message']['grounded'])->toBeFalse()->and($r['message']['content'])->toBe(CoachService::FALLBACK)->and($r['message']['content'])->not->toContain('98765');
});

it('lets the model repeat a number from the question', function () {
    $u = loggedInUser();
    coachFake([coachText('Yes, 3 meals a day is fine.')]);
    $r = $this->actingAs($u)->postJson('/coach/messages', ['text' => 'Is 3 meals a day ok?'])->assertOk()->json();
    expect($r['message']['grounded'])->toBeTrue();
});

it('survives an API failure with a plain message', function () {
    $u = loggedInUser();
    coachFake([new RuntimeException('boom')]);
    $r = $this->actingAs($u)->postJson('/coach/messages', ['text' => 'Anything?'])->assertOk()->json();
    expect($r['message']['content'])->toContain('could not be reached');
});

it('stops after max_rounds of tool calls', function () {
    $u = loggedInUser();
    config(['fuel.coach.max_rounds' => 3]);
    $f = coachFake(array_fill(0, 5, coachUse('get_assumptions')));
    $r = $this->actingAs($u)->postJson('/coach/messages', ['text' => 'Loop forever'])->assertOk()->json();
    expect($f->sent)->toHaveCount(3)->and($r['message']['grounded'])->toBeFalse();
});

it('offers only read-only tools', function () {
    $names = array_column(app(CoachTools::class)->definitions(), 'name');
    expect($names)->toHaveCount(10);
    foreach ($names as $n) {
        expect($n)->toStartWith('get_');
    }
    expect(app(CoachTools::class)->run('log_food', [], loggedInUser()))->toBe(['error' => 'Unknown tool.']);
});

it('does not change any data while answering', function () {
    $u = loggedInUser();
    $tables = ['food_logs', 'target_histories', 'workout_logs', 'weights', 'profiles'];
    $count = fn () => array_map(fn ($t) => \Illuminate\Support\Facades\Schema::hasTable($t) ? DB::table($t)->count() : -1, $tables);
    $before = $count();
    $tools = app(CoachTools::class);
    foreach (array_column($tools->definitions(), 'name') as $n) {
        $tools->run($n, [], $u);
    }
    expect($count())->toBe($before);
});

it('blocks a message over the monthly token cap, and keeps threads private', function () {
    $u = loggedInUser();
    config(['fuel.coach.monthly_token_cap' => 20]);
    coachFake([coachText('Fine.'), coachText('Fine.')]);
    $r = $this->actingAs($u)->postJson('/coach/messages', ['text' => 'first question'])->assertOk()->json();
    DB::table('coach_messages')->where('thread_id', $r['thread_id'])->update(['tokens' => 50]);
    $this->actingAs($u)->postJson('/coach/messages', ['text' => 'second question', 'thread_id' => $r['thread_id']])->assertStatus(429);

    config(['fuel.coach.monthly_token_cap' => 1000000]);
    $other = \App\Models\User::factory()->create();
    $this->actingAs($other)->postJson('/coach/messages', ['text' => 'peek at it', 'thread_id' => $r['thread_id']])->assertNotFound();
    $this->actingAs($other)->delete("/coach/threads/{$r['thread_id']}");
    expect(DB::table('coach_threads')->where('id', $r['thread_id'])->exists())->toBeTrue();
    $this->actingAs($u)->delete("/coach/threads/{$r['thread_id']}")->assertRedirect('/coach');
    expect(DB::table('coach_threads')->where('id', $r['thread_id'])->exists())->toBeFalse()->and(DB::table('coach_messages')->where('thread_id', $r['thread_id'])->count())->toBe(0);
});
