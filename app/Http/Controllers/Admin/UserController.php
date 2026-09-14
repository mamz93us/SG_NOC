<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Identity\EntraUserAccounts;
use App\Support\HrPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(EntraUserAccounts $accounts)
    {
        $users = User::orderBy('name')->get();
        $roles = Role::assignable()->get();

        $signInHints = $roles->mapWithKeys(fn (Role $role) => [$role->slug => $this->signInHint($role)]);

        // A failed "From Entra" submit re-opens the modal with the person still
        // chosen, rather than making the admin search for them again.
        $oldEmployee = old('source') === 'entra' && old('employee_id')
            ? Employee::with(['identityUser', 'branch:id,name'])->find(old('employee_id'))
            : null;

        $oldPick = $oldEmployee ? $accounts->describe($oldEmployee) : null;

        return view('admin.users.index', compact('users', 'roles', 'signInHints', 'oldPick'));
    }

    public function store(Request $request, EntraUserAccounts $accounts)
    {
        if ($request->input('source') === 'entra') {
            return $this->storeFromEntra($request, $accounts);
        }

        $data = $this->validateUser($request);

        $this->assertMayAssign($data['role']);

        // No ActivityLog write here: the generic AuditObserver records the
        // create, and does it better — it redacts the password hash and captures
        // every column rather than three of them.
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', "User {$user->name} created as ".User::roleLabel($user->role).'.');
    }

    /**
     * Type-ahead for "Add user ▸ From Entra".
     * GET /admin/users/entra-search?q=...
     */
    public function entraSearch(Request $request, EntraUserAccounts $accounts): JsonResponse
    {
        return response()->json($accounts->search((string) $request->query('q', '')));
    }

    /**
     * Add a person who already exists in Entra: pick the employee, pick the
     * role. Name and address come from their record and there is no password —
     * they sign in with Microsoft. If they already have an account (usually a
     * first SSO sign-in that gave them the default role), the role is applied
     * to that account instead of creating a second one SSO would never use.
     */
    private function storeFromEntra(Request $request, EntraUserAccounts $accounts)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'role' => ['required', 'string', Rule::in(Role::slugs())],
            'whatsapp_number' => 'nullable|string|max:32',
        ], [
            'employee_id.required' => 'Choose the employee from the search list.',
        ]);

        $employee = Employee::with('identityUser')->find($data['employee_id']);

        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => 'That employee no longer exists.']);
        }

        if ($reason = $accounts->unavailableReason($employee)) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->name}: {$reason}"]);
        }

        $this->assertMayAssign($data['role']);

        if ($existing = $accounts->existingUser($employee)) {
            $this->assertNotLastSuperAdmin($existing, $data['role']);
        }

        // The AuditObserver records the create or the role change.
        ['user' => $user, 'previous_role' => $previous] = $accounts->assign(
            $employee,
            $data['role'],
            $data['whatsapp_number'] ?? null,
        );

        $role = User::roleLabel($user->role);

        $message = match (true) {
            $previous === null => "{$user->name} added as {$role}. They sign in with their Microsoft account ({$user->email}) — there is no password to send.",
            $previous === $user->role => "{$user->name} already had an account as {$role}. Nothing to change.",
            default => "{$user->name} already had an account, so it was updated: ".User::roleLabel($previous)." → {$role}.",
        };

        return redirect()->route('admin.users.index')->with('success', $message);
    }

    /**
     * One line under the role picker saying where this role signs in and
     * whether 2FA is asked for — the question every HR hire raises.
     */
    private function signInHint(Role $role): string
    {
        return match (true) {
            $role->is_super, $role->hasSurface('noc_admin') => 'Signs in to the NOC with Microsoft and sets up 2FA the first time.',
            $role->hasSurface('hr_portal') => 'Uses the HR Portal at '.HrPortal::domain().' with Microsoft sign-in — no 2FA there.',
            $role->onlyBrowserAccess() => 'Remote browser only — Microsoft sign-in, no 2FA.',
            $role->hasSurface('marketing_portal') => 'Uses the marketing portal with Microsoft sign-in, plus 2FA.',
            default => 'Signs in with Microsoft and sets up 2FA the first time.',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function validateUser(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => $user
                ? ['nullable', Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()]
                : ['required', Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()],
            'role' => ['required', 'string', Rule::in(Role::slugs())],
            'whatsapp_number' => 'nullable|string|max:32',
        ]);
    }

    /**
     * Only a superuser may hand out a superuser role.
     *
     * `manage-users` is excluded from the admin role by default, but it is a
     * grantable permission — so without this check anyone given it could make
     * themselves (or anyone else) a Super Admin, which is a one-step escalation
     * past every other permission on the matrix.
     */
    private function assertMayAssign(string $roleSlug): void
    {
        $role = Role::findBySlug($roleSlug);

        if (! $role?->is_super) {
            return;
        }

        if (Auth::user()?->isSuperAdmin()) {
            return;
        }

        throw ValidationException::withMessages([
            'role' => 'Only a Super Admin can assign the Super Admin role.',
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validateUser($request, $user);

        $this->assertMayAssign($data['role']);

        // Taking the last superuser's role away locks everyone out of user and
        // permission management for good — those two permissions are held by no
        // other role by default, and the only screen that could grant them is
        // the one being locked.
        $this->assertNotLastSuperAdmin($user, $data['role']);

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $data['role'];
        $user->whatsapp_number = $data['whatsapp_number'] ?? null;

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        // The AuditObserver records the diff, with the password hash redacted.
        $user->save();

        // A role change rewrites what this person can reach, so drop their
        // resolved-permission cache rather than waiting for the request to end.
        User::clearOverrideCache($user->id);

        return redirect()->route('admin.users.index')
            ->with('success', "User {$user->name} updated successfully.");
    }

    /**
     * Refuse to demote the last remaining superuser.
     */
    private function assertNotLastSuperAdmin(User $user, string $newRole): void
    {
        if (! $user->isSuperAdmin()) {
            return;
        }

        if (Role::findBySlug($newRole)?->is_super) {
            return; // still a superuser
        }

        $superSlugs = Role::cached()->where('is_super', true)->pluck('slug')->all();

        $remaining = User::whereIn('role', $superSlugs)
            ->where('id', '!=', $user->id)
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'role' => 'This is the only Super Admin. Promote someone else first, or user and permission management becomes unreachable.',
            ]);
        }
    }

    public function resetTwoFactor(User $user)
    {
        if ($user->id === Auth::id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'Use the Two-Factor settings page to manage your own 2FA.');
        }

        if (!$user->hasTwoFactorEnabled() && empty($user->two_factor_secret)) {
            return redirect()->route('admin.users.index')
                ->with('error', "Two-factor authentication is not set up for {$user->name}.");
        }

        $user->forceFill([
            'two_factor_secret'       => null,
            'two_factor_enabled'      => false,
            'two_factor_confirmed_at' => null,
        ])->save();

        ActivityLog::create([
            'model_type' => 'User',
            'model_id'   => $user->id,
            'action'     => 'two_factor_reset',
            'changes'    => ['name' => $user->name, 'email' => $user->email],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', "Two-factor authentication reset for {$user->name}. They must re-enroll on next login.");
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'You cannot delete your own account.');
        }

        // Same reasoning as a demotion: deleting the last superuser strands
        // user and permission management.
        $superSlugs = Role::cached()->where('is_super', true)->pluck('slug')->all();

        if ($user->isSuperAdmin() && User::whereIn('role', $superSlugs)->where('id', '!=', $user->id)->count() === 0) {
            return redirect()->route('admin.users.index')
                ->with('error', 'This is the only Super Admin. Promote someone else before deleting this account.');
        }

        $name = $user->name;

        // The AuditObserver records the delete with the full (redacted) row.
        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', "User {$name} deleted.");
    }
}
