<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    /**
     * Show the 2FA setup / status page.
     */
    public function setup(Request $request)
    {
        $user = $request->user();
        $google2fa = new Google2FA();

        // If 2FA is already confirmed, show the enabled state (no secret needed)
        if ($user->hasTwoFactorEnabled()) {
            return view('auth.two-factor.setup', [
                'enabled' => true,
                'qrUrl'   => null,
                'secret'  => null,
            ]);
        }

        // Generate a new secret if the user doesn't have one yet
        $secret = $user->two_factor_secret;
        if (! $secret) {
            $secret = $user->generateTwoFactorSecret();
        }

        // Build the otpauth:// URI for the QR code
        $qrUrl = $google2fa->getQRCodeUrl(
            'SG NOC',
            $user->email,
            $secret
        );

        return view('auth.two-factor.setup', [
            'enabled' => false,
            'qrUrl'   => $qrUrl,
            'secret'  => $secret,
        ]);
    }

    /**
     * Confirm and enable 2FA after verifying a valid TOTP code.
     */
    public function confirm(Request $request)
    {
        $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();
        $google2fa = new Google2FA();

        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->input('code'));

        if (! $valid) {
            return redirect()->back()->withErrors(['code' => 'The provided code is invalid. Please try again.']);
        }

        $user->update([
            'two_factor_enabled'      => true,
            'two_factor_confirmed_at' => now(),
        ]);

        // Mark the current session as 2FA-verified so the middleware won't kick in
        $request->session()->put('2fa_verified', true);

        return redirect()->route('admin.two-factor.setup')
            ->with('success', 'Two-factor authentication has been enabled successfully.');
    }

    /**
     * Disable 2FA after verifying the user's password.
     */
    public function disable(Request $request)
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            return redirect()->back()->withErrors(['password' => 'The password you entered is incorrect.']);
        }

        $user->update([
            'two_factor_secret'       => null,
            'two_factor_enabled'      => false,
            'two_factor_confirmed_at' => null,
        ]);

        // Clear the session flag
        $request->session()->forget('2fa_verified');

        return redirect()->route('admin.two-factor.setup')
            ->with('success', 'Two-factor authentication has been disabled.');
    }
}
