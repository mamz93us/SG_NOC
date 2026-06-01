<?php

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * The challenge screen auto-verifies over AJAX so it can play the
 * loading → green-check animation before navigating. These tests pin
 * the JSON contract that the front-end relies on.
 */
function makeTwoFactorUser(string $secret): User
{
    return User::factory()->create([
        'two_factor_secret'       => $secret,
        'two_factor_enabled'      => true,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('returns json with a redirect target when the code is valid', function () {
    $google2fa = new Google2FA();
    $secret = $google2fa->generateSecretKey();
    $user = makeTwoFactorUser($secret);

    $response = $this->actingAs($user)->postJson(
        route('two-factor.verify'),
        ['code' => $google2fa->getCurrentOtp($secret)],
    );

    $response->assertOk()
        ->assertJson(['valid' => true])
        ->assertJsonStructure(['valid', 'redirect']);

    expect(session()->get('2fa_verified'))->toBeTrue();
});

it('returns a 422 json error when the code is invalid', function () {
    $google2fa = new Google2FA();
    $secret = $google2fa->generateSecretKey();
    $user = makeTwoFactorUser($secret);

    // A static, definitely-stale code — never the current/adjacent OTP window.
    $response = $this->actingAs($user)->postJson(
        route('two-factor.verify'),
        ['code' => '000000'],
    );

    $response->assertStatus(422)
        ->assertJson(['valid' => false])
        ->assertJsonStructure(['valid', 'message']);

    expect(session()->get('2fa_verified'))->toBeNull();
});

it('still redirects (no json) for a plain form post', function () {
    $google2fa = new Google2FA();
    $secret = $google2fa->generateSecretKey();
    $user = makeTwoFactorUser($secret);

    $response = $this->actingAs($user)->post(
        route('two-factor.verify'),
        ['code' => $google2fa->getCurrentOtp($secret)],
    );

    $response->assertRedirect();
    expect(session()->get('2fa_verified'))->toBeTrue();
});
