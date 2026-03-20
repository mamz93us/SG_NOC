<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallengeController extends Controller
{
    /**
     * Show the 2FA challenge form.
     */
    public function show(Request $request)
    {
        if (! $request->session()->has('2fa_user_id')) {
            return redirect()->route('login');
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

        $userId = $request->session()->get('2fa_user_id');

        if (! $userId) {
            return redirect()->route('login');
        }

        $user = User::findOrFail($userId);
        $google2fa = new Google2FA();

        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->input('code'));

        if (! $valid) {
            return redirect()->back()->withErrors(['code' => 'The authentication code is invalid. Please try again.']);
        }

        // Code is valid — complete login
        $remember = $request->session()->get('2fa_remember', false);
        Auth::login($user, $remember);

        $request->session()->forget('2fa_user_id');
        $request->session()->forget('2fa_remember');
        $request->session()->put('2fa_verified', true);

        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }
}
