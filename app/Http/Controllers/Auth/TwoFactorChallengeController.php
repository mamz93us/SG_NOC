<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallengeController extends Controller
{
    /**
     * Show the 2FA challenge form.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return redirect()->route('login');
        }

        if ($request->session()->get('2fa_verified')) {
            return redirect()->route($user->homeRoute());
        }

        return view('auth.two-factor.challenge');
    }

    /**
     * Verify the 2FA code and complete login.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return redirect()->route('login');
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->input('code'));

        if (! $valid) {
            return redirect()->back()->withErrors([
                'code' => 'The authentication code is invalid. Please try again.',
            ]);
        }

        $request->session()->put('2fa_verified', true);
        $request->session()->regenerate();

        return redirect()->intended(route($user->homeRoute()));
    }
}
