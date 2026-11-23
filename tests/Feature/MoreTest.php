<?php

it('shows the More page to a signed-in user and sends guests to log in', function () {
    $this->get('/more')->assertRedirect();
    $this->actingAs(loggedInUser())->get('/more')->assertOk()->assertInertia(fn ($p) => $p->component('More'));
});

it('does not break Today or Progress when the only weigh-in is dated in the future', function () {
    $u = loggedInUser();
    \Illuminate\Support\Facades\DB::table('weight_logs')->where('user_id', $u->id)->delete();
    \Illuminate\Support\Facades\DB::table('weight_logs')->insert(['user_id' => $u->id, 'local_date' => now()->addDays(3)->toDateString(), 'kg' => 74, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($u)->get('/')->assertOk();
    $this->actingAs($u)->get('/progress')->assertOk();
});
