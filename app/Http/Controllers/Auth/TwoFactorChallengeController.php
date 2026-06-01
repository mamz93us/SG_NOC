<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
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
            if ($request->expectsJson()) {
                return response()->json([
                    'valid'    => false,
                    'redirect' => route('login'),
                ], 409);
            }

            return redirect()->route('login');
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->input('code'));

        if (! $valid) {
            ActivityLog::create([
                'model_type' => \App\Models\User::class,
                'model_id'   => $user->id,
                'action'     => '2fa_verify_failed',
                'changes'    => ['context' => 'challenge'],
                'user_id'    => $user->id,
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'valid'   => false,
                    'message' => 'The authentication code is invalid. Please try again.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'code' => 'The authentication code is invalid. Please try again.',
            ]);
        }

        $request->session()->put('2fa_verified', true);
        $request->session()->regenerate();

        ActivityLog::create([
            'model_type' => \App\Models\User::class,
            'model_id'   => $user->id,
            'action'     => '2fa_verified',
            'changes'    => null,
            'user_id'    => $user->id,
        ]);

        // Resolve the post-login target once (consumes the stored intended URL).
        $target = redirect()->intended(route($user->homeRoute()))->getTargetUrl();

        if ($request->expectsJson()) {
            return response()->json([
                'valid'    => true,
                'redirect' => $target,
            ]);
        }

        return redirect()->to($target);
    }
}
