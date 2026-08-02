<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\VCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The signed-in half of the digital business card subdomain.
 *
 * Employees land here after SSO and are sent straight to their own card, so they
 * see exactly what a customer sees — plus the owner-only Wallet and Share
 * actions. Everything public about a card is still served by EmployeeCardController.
 */
class VCardPortalController extends Controller
{
    /**
     * Send the signed-in user to their own card.
     */
    public function mine(Request $request)
    {
        $employee = $this->employeeFor($request);

        if (! $employee) {
            return response()->view('public.vcard-no-record', [
                'email' => Auth::user()?->email,
            ], 404);
        }

        // Cards are created lazily: an employee who has never been shared from the
        // admin panel has no token yet, and would otherwise have nothing to open.
        if (! $employee->card_token) {
            $employee->update(['card_token' => Str::uuid()->toString()]);
        }

        return redirect()->away(VCard::cardUrl($employee->card_token));
    }

    /**
     * Resolve the Employee record behind the signed-in user.
     *
     * Same email-match the rest of the portal uses (Portal\PortalHubController,
     * MyAssetsController) — there is no FK between users and employees.
     */
    private function employeeFor(Request $request): ?Employee
    {
        $email = Auth::user()?->email;

        if (! $email) {
            return null;
        }

        return Employee::where('email', $email)
            ->where('status', 'active')
            ->first();
    }
}
