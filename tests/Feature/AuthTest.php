<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('sends guests to the login page', function () {
    $this->get('/')->assertRedirect('/login');
    $this->get('/settings/security')->assertRedirect('/login');
});

it('renders the login page with registration offered while no user exists', function () {
    $this->get('/login')->assertInertia(fn (Assert $p) => $p->component('auth/Login')->where('canRegister', true));
});

it('closes registration once the first user exists', function () {
    $this->post('/register', ['name' => 'Jay', 'email' => 'jay@example.com', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])
        ->assertRedirect('/');
    expect(User::count())->toBe(1);

    auth()->logout();
    $this->get('/register')->assertRedirect('/login');
    $this->post('/register', ['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])
        ->assertForbidden();
    expect(User::count())->toBe(1);
    $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('canRegister', false));
});

it('keeps registration closed when REGISTRATION_ENABLED is false', function () {
    config(['fuel.registration_enabled' => false]);
    $this->get('/register')->assertRedirect('/login');
    $this->post('/register', ['name' => 'Jay', 'email' => 'jay@example.com', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])
        ->assertForbidden();
    expect(User::count())->toBe(0);
});

it('signs in with a password', function () {
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password without leaking which part was wrong', function () {
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('throttles repeated failed logins', function () {
    $user = User::factory()->create();
    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'nope']);
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
    $this->assertGuest();
});

it('uses a one year remember-me cookie lifetime', function () {
    expect(config('auth.guards.web.remember'))->toBe(525600);
});

it('puts the security page behind password confirmation', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/settings/security')->assertRedirect('/user/confirm-password');

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/security')
        ->assertInertia(fn (Assert $p) => $p->component('settings/Security')->has('passkeys', 0));
});
