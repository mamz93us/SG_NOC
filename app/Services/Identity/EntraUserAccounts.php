<?php

namespace App\Services\Identity;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sign-in accounts for people who already exist in Entra.
 *
 * Adding a user used to mean typing a name, an email and a password: a local
 * account, when everyone actually reaches the NOC and its portals through
 * Microsoft SSO. This picks the person from the employee directory instead and
 * writes the row SSO will find, with no password anyone could use.
 *
 * The address is the part that matters. MicrosoftController matches a sign-in
 * to a user by `userPrincipalName` — that is what Socialite's getEmail()
 * returns for Microsoft — and CREATES a fresh default-role user when nothing
 * matches. An account stored under any other address would sit unused while
 * its owner signed in as somebody new, so the UPN from the Entra mirror wins
 * over the employee's work email whenever both exist.
 */
class EntraUserAccounts
{
    /**
     * Employees with an Entra account whose name, work email, UPN or Oracle
     * number matches, each flagged with whether they can be added and whether
     * they already have an account.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $employees = Employee::query()
            ->with(['identityUser', 'branch:id,name'])
            ->whereNotNull('azure_id')
            ->where('azure_id', '!=', '')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'terminated'))
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('oracle_emp_no', 'like', "%{$term}%")
                    ->orWhereHas('identityUser', fn ($i) => $i->where('user_principal_name', 'like', "%{$term}%"));
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();

        // One query for every candidate's possible account, not one per row.
        $users = $this->usersByEmail($employees->flatMap(fn (Employee $e) => $this->addressesOf($e)));

        return $employees
            ->map(fn (Employee $employee) => $this->row($employee, $this->pick($users, $employee)))
            ->values()
            ->all();
    }

    /**
     * One employee in the same shape search() returns.
     *
     * @return array<string, mixed>
     */
    public function describe(Employee $employee): array
    {
        return $this->row($employee, $this->existingUser($employee));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Employee $employee, ?User $existing): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $this->signInEmail($employee),
            'job_title' => $employee->job_title,
            'branch' => $employee->branch?->name,
            'emp_no' => $employee->oracle_emp_no,
            'unavailable' => $this->unavailableReason($employee),
            'user' => $existing ? [
                'id' => $existing->id,
                'role' => $existing->role,
                'role_label' => User::roleLabel($existing->role),
            ] : null,
        ];
    }

    /**
     * The address Microsoft SSO will present for this person.
     */
    public function signInEmail(Employee $employee): ?string
    {
        $address = trim((string) ($employee->identityUser?->user_principal_name ?: $employee->email));

        return $address !== '' ? $address : null;
    }

    /**
     * Why this employee cannot be given a sign-in account, or null if they can.
     */
    public function unavailableReason(Employee $employee): ?string
    {
        if (blank($employee->azure_id)) {
            return 'This employee has no Entra account, so they cannot sign in with Microsoft.';
        }

        if ($employee->status === 'terminated') {
            return 'This employee has left the company.';
        }

        // No mirror row yet (the identity sync has not seen them) is allowed:
        // the work email stands in. A row that says disabled is not.
        if ($employee->identityUser && ! $employee->identityUser->account_enabled) {
            return 'Their Entra account is disabled, so they cannot sign in.';
        }

        if (! $this->signInEmail($employee)) {
            return 'There is no sign-in address on their record.';
        }

        return null;
    }

    /**
     * The account this person already has, whichever of their addresses it was
     * created under — typically a first SSO sign-in that made them the default
     * role before anyone added them on purpose.
     */
    public function existingUser(Employee $employee): ?User
    {
        return $this->pick($this->usersByEmail($this->addressesOf($employee)), $employee);
    }

    /**
     * Give this person the role: create their account, or change the role on
     * the one they already have. Authorisation (who may hand out which role)
     * is the caller's job.
     *
     * @return array{user: User, previous_role: ?string}
     */
    public function assign(Employee $employee, string $role, ?string $whatsappNumber = null): array
    {
        $user = $this->existingUser($employee);

        if ($user) {
            $previous = $user->role;

            $user->role = $role;
            if (filled($whatsappNumber)) {
                $user->whatsapp_number = $whatsappNumber;
            }
            $user->save();

            User::clearOverrideCache($user->id);

            return ['user' => $user, 'previous_role' => $previous];
        }

        $user = User::create([
            'name' => $employee->name,
            'email' => $this->signInEmail($employee),
            // Unusable by design, as for any SSO-created user: they sign in
            // with Microsoft, and nobody is ever told this value.
            'password' => Str::random(40),
            'role' => $role,
            'whatsapp_number' => $whatsappNumber,
        ]);

        return ['user' => $user, 'previous_role' => null];
    }

    /**
     * Every address an account for this person could be stored under, in
     * order of preference: the UPN SSO uses first.
     *
     * @return array<int, string>
     */
    private function addressesOf(Employee $employee): array
    {
        return collect([
            $employee->identityUser?->user_principal_name,
            $employee->identityUser?->mail,
            $employee->email,
        ])
            ->map(fn ($address) => trim((string) $address))
            ->filter()
            ->unique(fn ($address) => Str::lower($address))
            ->values()
            ->all();
    }

    /**
     * @param  iterable<string>  $addresses
     * @return Collection<string, User> keyed by lower-cased email
     */
    private function usersByEmail(iterable $addresses): Collection
    {
        // Both spellings, so the lookup does not depend on the collation: MySQL
        // compares case-insensitively, SQLite does not.
        $candidates = collect($addresses)
            ->flatMap(fn ($address) => [$address, Str::lower($address)])
            ->unique()
            ->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        return User::whereIn('email', $candidates->all())
            ->get()
            ->keyBy(fn (User $user) => Str::lower($user->email));
    }

    private function pick(Collection $users, Employee $employee): ?User
    {
        foreach ($this->addressesOf($employee) as $address) {
            if ($user = $users->get(Str::lower($address))) {
                return $user;
            }
        }

        return null;
    }
}
