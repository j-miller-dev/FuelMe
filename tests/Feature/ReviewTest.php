<?php

use App\Domain\Progress\WeeklyReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('renders a weekly review from the template when the API is off', function () {
    config(['fuel.capture.api_key' => null]);
    $u = loggedInUser();
    $this->actingAs($u)->post('/review/generate')->assertRedirect('/review');

    $row = DB::table('weekly_reviews')->where('user_id', $u->id)->first();
    expect($row->generated_by)->toBe('template')->and(str_word_count($row->note_text))->toBeLessThan(150)
        ->and(WeeklyReview::groundedNumbers($row->note_text, json_decode($row->review_json, true)))->toBeTrue();
    $this->actingAs($u)->get('/review')->assertInertia(fn ($p) => $p->component('Review')->has('reviews', 1)->where('reviews.0.generated_by', 'template'));
});

it('keeps the template when the model note invents a number', function () {
    config(['fuel.capture.api_key' => 'test', 'fuel.review.use_model' => true]);
    Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'Great week! Eat 9999 kcal tomorrow.']]])]);
    $u = loggedInUser();
    $this->actingAs($u)->post('/review/generate');
    expect(DB::table('weekly_reviews')->where('user_id', $u->id)->value('generated_by'))->toBe('template');
});

it('uses the model note when every number is grounded', function () {
    config(['fuel.capture.api_key' => 'test', 'fuel.review.use_model' => true]);
    Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'A steady week. Keep going as you are.']]])]);
    $u = loggedInUser();
    $this->actingAs($u)->post('/review/generate');
    $row = DB::table('weekly_reviews')->where('user_id', $u->id)->first();
    expect($row->generated_by)->toBe('model')->and($row->note_text)->toBe('A steady week. Keep going as you are.');
});

it('only writes the scheduled review on Sunday evening, once', function () {
    $u = loggedInUser();
    $tz = $u->profile->tz;
    $this->travelTo(now($tz)->next('Wednesday')->setTime(19, 0));
    $this->artisan('review:weekly')->assertSuccessful();
    expect(DB::table('weekly_reviews')->count())->toBe(0);

    $this->travelTo(now($tz)->next('Sunday')->setTime(19, 0));
    $this->artisan('review:weekly')->assertSuccessful();
    $this->artisan('review:weekly')->assertSuccessful();
    expect(DB::table('weekly_reviews')->where('user_id', $u->id)->count())->toBe(1);
});

it('says not achievable safely for an event that needs far more than the safe rate', function () {
    $u = loggedInUser();
    $trend = $this->actingAs($u)->get('/review')->viewData('page')['props']['today'];
    $kg = (float) DB::table('weight_logs')->where('user_id', $u->id)->orderByDesc('local_date')->value('kg') ?: 80.0;
    $this->actingAs($u)->put('/event', ['event_date' => date('Y-m-d', strtotime('+7 days')), 'target_kg' => round($kg * 0.9, 1)])->assertRedirect('/review');

    $ev = $this->actingAs($u)->get('/review')->viewData('page')['props']['event'];
    expect($ev['assessment']['verdict'])->toBe('not_achievable_safely');
    $this->actingAs($u)->delete('/event')->assertRedirect();
    expect(DB::table('events')->count())->toBe(0);
});

it('rejects a past event date and a silly weight', function () {
    $u = loggedInUser();
    $this->actingAs($u)->put('/event', ['event_date' => date('Y-m-d', strtotime('-1 day')), 'target_kg' => 80])->assertSessionHasErrors('event_date');
    $this->actingAs($u)->put('/event', ['event_date' => date('Y-m-d', strtotime('+30 days')), 'target_kg' => 5])->assertSessionHasErrors('target_kg');
});
