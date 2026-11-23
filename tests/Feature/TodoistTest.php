<?php

use App\Services\Todoist\TodoistExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const TD_TOKEN = 'todoist-test-token-1234567890';

function tdFake(int $checkStatus = 200, ?array $tasksFail = null): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    $n = 0;
    Http::fake([
        '*/api/v1/projects*' => fn ($r) => $r->method() === 'GET' ? Http::response(['results' => []], $checkStatus) : Http::response(['id' => 'proj-1', 'name' => 'FUEL shopping']),
        '*/api/v1/tasks' => function () use (&$n, $tasksFail) {
            $n++;

            return $tasksFail && $n > $tasksFail['after'] ? Http::response(['error' => 'x'], $tasksFail['status']) : Http::response(['id' => "task-$n"]);
        },
    ]);
}

function tdList($u): int
{
    $list = DB::table('shopping_lists')->insertGetId(['user_id' => $u->id, 'name' => 'Week', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $items = [['Chicken breast', 1200, 'g', 'need', 0], ['Milk', 2000, 'ml', 'need', 500], ['Rice', 500, 'g', 'bought', 0], ['Salt', null, null, 'need', 0], ['Oats', 300, 'g', 'need', 300], ['Eggs', 12, 'count', 'have', 0]];
    foreach ($items as $i => [$label, $qty, $unit, $state, $have]) {
        DB::table('shopping_list_items')->insert(['list_id' => $list, 'ingredient_key' => "k$i", 'label' => $label, 'qty' => $qty, 'unit' => $unit, 'have_qty' => $have ?: null, 'aisle' => 'Pantry', 'state' => $state,
            'source_meals' => json_encode(['Chicken bowl']), 'is_manual' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $list;
}

it('connects with a token that Todoist accepts, stores it encrypted, and never shows it again', function () {
    $u = loggedInUser();
    tdFake();

    $this->actingAs($u)->post('/todoist/connect', ['token' => TD_TOKEN])->assertRedirect();

    expect(DB::table('todoist_connections')->value('api_token'))->not->toContain(TD_TOKEN);
    $this->actingAs($u)->get('/settings/todoist')->assertOk()->assertInertia(fn ($p) => $p->where('connected', true));
    expect($this->actingAs($u)->get('/settings/todoist')->getContent())->not->toContain(TD_TOKEN);
});

it('says so plainly when Todoist rejects the token, and keeps nothing', function () {
    $u = loggedInUser();
    tdFake(401);

    $this->actingAs($u)->post('/todoist/connect', ['token' => TD_TOKEN])->assertSessionHasErrors(['token' => 'Todoist did not accept that token.']);
    expect(DB::table('todoist_connections')->count())->toBe(0);
});

it('sends only what you still need, with quantities, and adds nothing twice', function () {
    $u = loggedInUser();
    tdFake();
    $this->actingAs($u)->post('/todoist/connect', ['token' => TD_TOKEN]);
    $list = tdList($u);

    $first = $this->actingAs($u)->postJson("/shopping/{$list}/todoist")->assertOk()->json();
    expect($first)->toMatchArray(['sent' => 3, 'error' => null]);   // chicken, milk (net of pantry), salt. Not rice (bought), oats (pantry covers it), eggs (have)

    $contents = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_ends_with($r->url(), '/api/v1/tasks'))->map(fn ($r) => $r->data()['content'])->values()->all();
    expect($contents)->toBe(['Chicken breast, 1.2 kg', 'Milk, 1.5 L', 'Salt']);
    expect(DB::table('todoist_connections')->value('project_id'))->toBe('proj-1');

    $again = $this->actingAs($u)->postJson("/shopping/{$list}/todoist")->assertOk()->json();
    expect($again)->toMatchArray(['sent' => 0, 'error' => null])->and(DB::table('todoist_exports')->count())->toBe(3);
});

it('reports a partial send honestly and picks up where it stopped', function () {
    $u = loggedInUser();
    tdFake(200, ['after' => 1, 'status' => 500]);
    $this->actingAs($u)->post('/todoist/connect', ['token' => TD_TOKEN]);
    $list = tdList($u);

    $r = $this->actingAs($u)->postJson("/shopping/{$list}/todoist")->assertOk()->json();
    expect($r['sent'])->toBe(1)->and($r['error'])->toContain('error (500)')->and(DB::table('todoist_exports')->count())->toBe(1);

    tdFake();
    $r2 = $this->actingAs($u)->postJson("/shopping/{$list}/todoist")->json();
    expect($r2['sent'])->toBe(2)->and(DB::table('todoist_exports')->count())->toBe(3);
});

it('will not send someone else\'s list, and needs a connection', function () {
    $u = loggedInUser();
    $other = \App\Models\User::factory()->create();
    $list = tdList($u);

    $this->actingAs($other)->postJson("/shopping/{$list}/todoist")->assertNotFound();
    expect($this->actingAs($u)->postJson("/shopping/{$list}/todoist")->json())->toMatchArray(['sent' => 0, 'error' => 'Todoist is not connected.']);
});

it('formats quantities for a task title', function () {
    expect(TodoistExport::qtyText(1200, 'g'))->toBe('1.2 kg')->and(TodoistExport::qtyText(450, 'g'))->toBe('450 g')->and(TodoistExport::qtyText(1500, 'ml'))->toBe('1.5 L')
        ->and(TodoistExport::qtyText(3, 'count'))->toBe('3')->and(TodoistExport::qtyText(null, null))->toBe('')->and(TodoistExport::qtyText(1000, 'g'))->toBe('1 kg');
});
