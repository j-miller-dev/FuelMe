<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('shows data-source attribution with live counts, behind login', function () {
    $this->get('/settings/about')->assertRedirect('/login');

    DB::table('foods')->insert(['source' => 'afcd', 'source_id' => 'F1', 'name' => 'A', 'quality' => 80, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('foods')->insert(['source' => 'off', 'source_id' => '9300000000001', 'barcode' => '9300000000001', 'name' => 'B', 'quality' => 60, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs(User::factory()->create())->get('/settings/about')
        ->assertInertia(fn (Assert $p) => $p->component('settings/About')->where('counts.afcd', 1)->where('counts.off', 1)->where('counts.user', 0));
});
