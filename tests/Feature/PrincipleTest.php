<?php

use App\Services\Coach\CoachTools;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

function noteFile(string $name, string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

function importNotes($test, $user, array $files, bool $full = false)
{
    return $test->actingAs($user)->post('/principles/import', ['files' => $files, 'full_sync' => $full], ['Accept' => 'application/json']);
}

$sleepNote = "---\nid: sleep-first\nsource: My notes\ntags: [recovery, sleep]\ngrade: strong\nstance: follow\n---\n# Sleep before volume\nProgress is decided in recovery, so protect sleep first.";

it('imports notes idempotently and updates by id', function () use ($sleepNote) {
    $u = loggedInUser();
    importNotes($this, $u, [noteFile('sleep-first.md', $sleepNote)])->assertOk()->assertJson(['created' => 1, 'updated' => 0, 'unchanged' => 0]);
    importNotes($this, $u, [noteFile('sleep-first.md', $sleepNote)])->assertJson(['created' => 0, 'unchanged' => 1]);
    importNotes($this, $u, [noteFile('renamed.md', str_replace('protect sleep first', 'guard sleep first', $sleepNote))])->assertJson(['updated' => 1, 'created' => 0]);
    expect(DB::table('principles')->where('user_id', $u->id)->count())->toBe(1)->and(DB::table('principles')->value('body'))->toContain('guard sleep');
});

it('reports bad notes without failing the rest', function () use ($sleepNote) {
    $u = loggedInUser();
    $r = importNotes($this, $u, [noteFile('ok.md', $sleepNote), noteFile('book.md', "# Chapter\n" . str_repeat('copied text ', 300))])->assertOk()->json();
    expect($r['created'])->toBe(1)->and($r['errors'])->toHaveCount(1)->and($r['errors'][0]['file'])->toBe('book.md');
});

it('archives missing notes only on a full sync, and a re-import reactivates', function () use ($sleepNote) {
    $u = loggedInUser();
    $other = "# Other idea\nSome other idea in my own words.";
    importNotes($this, $u, [noteFile('sleep-first.md', $sleepNote), noteFile('other.md', $other)]);
    importNotes($this, $u, [noteFile('sleep-first.md', $sleepNote)])->assertJson(['archived' => 0]);
    importNotes($this, $u, [noteFile('sleep-first.md', $sleepNote)], true)->assertJson(['archived' => 1]);
    expect(DB::table('principles')->where('ext_id', 'other')->value('status'))->toBe('archived');
    importNotes($this, $u, [noteFile('other.md', $other)])->assertJson(['updated' => 1]);
    expect(DB::table('principles')->where('ext_id', 'other')->value('status'))->toBe('active');
});

it('lets your stance win over the note, and survives a re-import', function () use ($sleepNote) {
    $u = loggedInUser();
    importNotes($this, $u, [noteFile('sleep-first.md', $sleepNote)]);
    $id = DB::table('principles')->value('id');
    $this->actingAs($u)->patch("/principles/{$id}/stance", ['stance' => 'ignore'])->assertRedirect();
    importNotes($this, $u, [noteFile('sleep-first.md', $sleepNote)]);
    $this->actingAs($u)->get('/principles')->assertInertia(fn ($p) => $p->component('Principles')->where('cards.0.stance', 'ignore')->where('cards.0.note_stance', 'follow')->where('cards.0.overridden', true));
    expect(app(CoachTools::class)->run('search_principles', ['query' => 'sleep recovery'], $u)['cards'])->toBe([]);
    $this->actingAs($u)->patch("/principles/{$id}/stance", ['stance' => 'bogus'])->assertSessionHasErrors('stance');
});

it('serves cards to the coach tool with a warning that numbers are the author\'s', function () use ($sleepNote) {
    $u = loggedInUser();
    importNotes($this, $u, [noteFile('sleep-first.md', $sleepNote)]);
    $out = app(CoachTools::class)->run('search_principles', ['query' => 'how much does sleep matter'], $u);
    expect($out['cards'])->toHaveCount(1)->and($out['cards'][0]['source'])->toBe('My notes')->and($out['note'])->toContain('not the person');
    expect(app(CoachTools::class)->run('search_principles', ['query' => 'zzzz'], $u)['note'])->toContain('Do not invent');
});

it('keeps notes private per user', function () use ($sleepNote) {
    $a = loggedInUser();
    importNotes($this, $a, [noteFile('sleep-first.md', $sleepNote)]);
    $id = DB::table('principles')->value('id');
    $b = \App\Models\User::factory()->create();
    $this->actingAs($b)->patch("/principles/{$id}/stance", ['stance' => 'ignore'])->assertNotFound();
    $this->actingAs($b)->delete("/principles/{$id}")->assertNotFound();
    expect(app(CoachTools::class)->run('search_principles', ['query' => 'sleep'], $b)['cards'])->toBe([]);
});

it('shows one card on Today for the reason that applies, and none without notes', function () use ($sleepNote) {
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-22 10:00', 'Australia/Melbourne'));   // a Sunday: no session today, so the reason is the plain one
    $u = loggedInUser();
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('principle', null));
    importNotes($this, $u, [noteFile('sleep-first.md', $sleepNote)]);
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('principle.title', 'Sleep before volume')->where('principle.reason', 'From your notes'));
    \Illuminate\Support\Carbon::setTestNow();
});

it('saves your stance text, which reaches the coach prompt, and the persona is style only', function () {
    $u = loggedInUser();
    $this->actingAs($u)->put('/principles/stance', ['text' => 'HIT for lifting.'])->assertRedirect();
    expect($u->fresh()->profile->coach_stance)->toBe('HIT for lifting.');

    config(['fuel.coach.enabled' => true, 'fuel.capture.api_key' => 'test']);
    $f = coachFake([coachText('Fine.')]);
    $this->actingAs($u)->postJson('/coach/messages', ['text' => 'Say hello please'])->assertOk();
    $sys = $f->sent[0]['system'];
    expect($sys)->toContain('HIT for lifting.')->toContain('ORIGINAL training-partner character')->toContain('every rule above still wins')->toContain('Every number in your answer must come straight from a tool result');

    config(['fuel.coach.persona' => 'neutral']);
    $f = coachFake([coachText('Fine.')]);
    $this->actingAs($u)->postJson('/coach/messages', ['text' => 'Say hello again'])->assertOk();
    expect($f->sent[0]['system'])->not->toContain('ORIGINAL training-partner character');
});
