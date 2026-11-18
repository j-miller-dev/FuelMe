<?php

use App\Models\User;

// The full WebAuthn ceremony needs a real authenticator (verified manually on a device over HTTPS).
// These tests prove the endpoints are wired, guarded and shaped the way resources/js/lib/passkeys.ts expects.

it('serves passkey login options to guests as browser-ready JSON', function () {
    $res = $this->getJson('/passkeys/login/options')->assertOk();
    expect($res->json('options.challenge'))->toBeString()->not->toBeEmpty()
        ->and($res->json('options.rpId'))->toBe('localhost');
});

it('rejects a malformed passkey login credential', function () {
    $this->getJson('/passkeys/login/options');
    $this->postJson('/passkeys/login', ['credential' => ['id' => 'x', 'rawId' => 'x', 'type' => 'public-key', 'response' => ['bogus' => true]]])
        ->assertStatus(422);
    $this->assertGuest();
});

it('requires sign-in and a recent password confirmation to register a passkey', function () {
    $this->getJson('/user/passkeys/options')->assertUnauthorized();

    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/user/passkeys/options')->assertStatus(423); // password confirmation required

    $res = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->getJson('/user/passkeys/options')->assertOk();
    expect($res->json('options.user.name'))->toBe($user->email)
        ->and($res->json('options.challenge'))->toBeString();
});

it('does not let one account delete another account\'s passkey', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $passkey = $owner->passkeys()->create(['name' => 'Phone', 'credential_id' => 'abc', 'credential' => ['x' => 1]]);

    $this->actingAs($other)->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson("/user/passkeys/{$passkey->id}")->assertForbidden();
    expect($owner->passkeys()->count())->toBe(1);
});
