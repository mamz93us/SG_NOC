<?php

namespace App\Services\Identity;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\IdentityUser;
use App\Services\People\EmployeeMerger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Gives a service employee the mailbox they turn out to have.
 *
 * Service employees — drivers, guards, warehouse and cleaning staff — reach the
 * NOC through the Oracle HR import with `email = null` always, because an
 * address in Oracle's EMAIL column is usually their manager's and copying it
 * would hand one person another's mailbox (see OracleHrImportService::mailboxOf).
 * That is right at import time and leaves no way back: when one of them does get
 * a company mailbox, nothing connects the two.
 *
 * Nothing else closes that gap. The Azure sync page matches an Entra account to
 * an employee **by email**, which can never match a record whose email is null,
 * and Identity ▸ Linked Accounts only groups records that share an email, a
 * mailbox name or a full name. Measured on 2026-09-20: of 77 service employees,
 * exactly one shares a name with an unmatched Entra account and two with an
 * existing employee record. So this is a manual tool for the occasional
 * promotion, not a bulk reconciliation — there is almost nothing to match
 * automatically, because these people genuinely have no mailboxes.
 *
 * A mailbox can be either of two things, and to whoever is linking it they are
 * the same job, so they are offered in one list:
 *
 *  - **an Entra account nobody holds** — the ordinary case. The address and the
 *    azure_id go onto the service record, and it stops being a service record.
 *  - **another employee record that already holds the address** — then the
 *    person is in the NOC twice, which is a merge and not a link. That is
 *    EmployeeMerger's job and it is handed straight to it, rather than growing a
 *    second, subtly different way to join two records.
 *
 * The guards matter more than usual here: `employees.email` has **no unique
 * index and no unique validation rule**, so nothing in the database stops the
 * same address landing on two people. Every path below checks for that by hand.
 */
class ServiceEmployeeMailboxLinker
{
    /** Kinds of candidate, as the picker labels them. */
    public const KIND_ENTRA = 'entra';

    public const KIND_EMPLOYEE = 'employee';

    public function __construct(private EmployeeMerger $merger) {}

    /**
     * May this employee be given a mailbox at all?
     *
     * Deliberately not "is this a service employee": the test is that they hold
     * no mailbox yet. Re-pointing an address somebody already has is a different
     * and far more dangerous operation, and it is not this one.
     */
    public function eligible(Employee $employee): bool
    {
        return $this->problemWith($employee) === null;
    }

    /** Why this employee cannot be given a mailbox, or null when they can. */
    public function problemWith(Employee $employee): ?string
    {
        if (filled($employee->email)) {
            return "{$employee->name} already has {$employee->email} on file. Change it on the employee form instead.";
        }

        if (filled($employee->azure_id)) {
            return "{$employee->name} is already linked to a Microsoft account.";
        }

        if ($employee->linked_primary_employee_id) {
            return "{$employee->name} is a linked account; give the mailbox to their main record.";
        }

        if ($employee->status === 'terminated') {
            return "{$employee->name} has left the company.";
        }

        return null;
    }

    /**
     * Mailboxes this employee could be given, both kinds in one list.
     *
     * A name match is offered first, because when there is one it is almost
     * always the answer — but it is only ever a suggestion. Nothing here links
     * anything on a name: the Oracle EMP_NO series collide and "Yousef Ahmed" is
     * not "Ahmed Yousef", which is exactly why the import refuses to guess.
     *
     * @return list<array{kind:string, ref:string, name:string, email:string, note:?string, suggested:bool}>
     */
    public function candidates(Employee $employee, ?string $search = null, int $limit = 25): array
    {
        $search = trim((string) $search);
        $nameKey = self::letters($employee->name);

        $candidates = array_merge(
            $this->entraCandidates($search, $limit),
            $this->employeeCandidates($employee, $search, $limit),
        );

        foreach ($candidates as &$candidate) {
            $candidate['suggested'] = $nameKey !== ''
                && strlen($nameKey) >= 6
                && self::letters($candidate['name']) === $nameKey;
        }
        unset($candidate);

        // Until something is typed, show only what is genuinely a suggestion.
        // The alternative is the first 25 accounts in the directory by name,
        // which look like candidates and are not — and for these people the
        // honest answer is usually that there is nothing to suggest at all.
        if ($search === '') {
            $candidates = array_values(array_filter($candidates, fn ($c) => $c['suggested']));
        }

        usort($candidates, fn ($a, $b) => [$b['suggested'], mb_strtolower($a['name'])]
            <=> [$a['suggested'], mb_strtolower($b['name'])]);

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Put an Entra account's mailbox on this employee.
     *
     * @return array{ok: bool, problems: list<string>}
     */
    public function linkEntra(Employee $employee, string $azureId): array
    {
        if ($problem = $this->problemWith($employee)) {
            return ['ok' => false, 'problems' => [$problem]];
        }

        $account = IdentityUser::where('azure_id', $azureId)->first();

        if (! $account) {
            return ['ok' => false, 'problems' => ['That Microsoft account is no longer in the NOC — run an identity sync and try again.']];
        }

        $email = self::addressOf($account);

        if ($email === null) {
            return ['ok' => false, 'problems' => ['That Microsoft account has no email address on it.']];
        }

        // employees.email has no unique index, so these two checks are the only
        // thing standing between an admin and two people holding one mailbox.
        if ($holder = Employee::where('azure_id', $azureId)->first()) {
            return ['ok' => false, 'problems' => ["{$holder->name} (#{$holder->id}) already holds that Microsoft account."]];
        }

        if ($holder = Employee::whereRaw('LOWER(email) = ?', [$email])->first()) {
            return ['ok' => false, 'problems' => ["{$holder->name} (#{$holder->id}) already holds {$email}."]];
        }

        DB::transaction(function () use ($employee, $account, $email, $azureId) {
            $employee->forceFill([
                'email' => $email,
                'azure_id' => $azureId,
                // The column means "holds a mailbox", so leaving it as service
                // would make it a lie. Nothing else in the app branches on it —
                // azure_id is what actually opens the contact sync, the home
                // portal and the signature templates.
                'employee_type' => Employee::TYPE_STANDARD,
            ])->save();

            // Oracle owns this person's HR fields and the import has already
            // written them, so the Entra account's job title and department are
            // NOT copied over — they are usually emptier and sometimes older.
            ActivityLog::create([
                'model_type' => Employee::class,
                'model_id' => $employee->id,
                'model_label' => $employee->name,
                'action' => 'service_employee_mailbox_linked',
                'changes' => [
                    'before' => ['email' => null, 'azure_id' => null, 'employee_type' => Employee::TYPE_SERVICE],
                    'after' => ['email' => $email, 'azure_id' => $azureId, 'employee_type' => Employee::TYPE_STANDARD],
                    'entra_display_name' => $account->display_name,
                    'oracle_emp_no' => $employee->oracle_emp_no,
                ],
                'user_id' => Auth::id(),
            ]);
        });

        return ['ok' => true, 'problems' => []];
    }

    /**
     * The same person held twice: merge rather than link.
     *
     * Handed to EmployeeMerger, which decides which record survives — the one
     * with the live Microsoft account — moves every row that points at the other
     * onto it, fills its blanks, and refuses outright on two live accounts, two
     * Oracle numbers or overlapping attendance days.
     *
     * @return array{ok: bool, problems: list<string>, kept: ?Employee}
     */
    public function mergeWithEmployee(Employee $employee, Employee $other): array
    {
        if ($employee->id === $other->id) {
            return ['ok' => false, 'problems' => ['That is the same record.'], 'kept' => null];
        }

        if ($problem = $this->problemWith($employee)) {
            return ['ok' => false, 'problems' => [$problem], 'kept' => null];
        }

        [$keep, $duplicates] = EmployeeMerger::order(Employee::whereKey([$employee->id, $other->id])->get());

        $problems = [];

        foreach ($duplicates as $duplicate) {
            $result = $this->merger->merge($keep->fresh(), $duplicate);

            if (! $result['merged']) {
                $problems = array_merge($problems, $result['problems']);
            }
        }

        return ['ok' => $problems === [], 'problems' => $problems, 'kept' => $keep->fresh()];
    }

    /**
     * Entra accounts no employee holds.
     *
     * @return list<array{kind:string, ref:string, name:string, email:string, note:?string, suggested:bool}>
     */
    private function entraCandidates(string $search, int $limit): array
    {
        $held = Employee::query()->whereNotNull('azure_id')->pluck('azure_id')->filter()->all();

        $query = IdentityUser::query()
            ->when($held !== [], fn ($q) => $q->whereNotIn('azure_id', $held))
            ->where(fn ($q) => $q->whereNotNull('mail')->orWhereNotNull('user_principal_name'));

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn ($q) => $q->where('display_name', 'like', $like)
                ->orWhere('mail', 'like', $like)
                ->orWhere('user_principal_name', 'like', $like));
        }

        $accounts = $query->orderBy('display_name')->limit($limit * 2)->get();
        $out = [];

        foreach ($accounts as $account) {
            $email = self::addressOf($account);

            if ($email === null) {
                continue;
            }

            $out[] = [
                'kind' => self::KIND_ENTRA,
                'ref' => (string) $account->azure_id,
                'name' => (string) ($account->display_name ?: $email),
                'email' => $email,
                'note' => $account->account_enabled ? $account->job_title : 'Disabled in Microsoft',
                'suggested' => false,
            ];
        }

        return $out;
    }

    /**
     * Employee records that already hold an address — the duplicate case.
     *
     * @return list<array{kind:string, ref:string, name:string, email:string, note:?string, suggested:bool}>
     */
    private function employeeCandidates(Employee $employee, string $search, int $limit): array
    {
        $query = Employee::query()
            ->whereKeyNot($employee->id)
            ->whereNull('linked_primary_employee_id')
            ->whereNotNull('email')
            ->where('email', '<>', '');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        } else {
            // With nothing typed, only offer records that look like the same
            // person — 727 mailboxes in a picker is not a choice, it is a list.
            $nameKey = self::letters($employee->name);

            if (strlen($nameKey) < 6) {
                return [];
            }

            $query->whereRaw(self::lettersSql('name').' = ?', [$nameKey]);
        }

        $out = [];

        foreach ($query->orderBy('name')->limit($limit)->get() as $other) {
            $out[] = [
                'kind' => self::KIND_EMPLOYEE,
                'ref' => (string) $other->id,
                'name' => (string) $other->name,
                'email' => (string) $other->email,
                'note' => 'Already an employee record — choosing this merges the two',
                'suggested' => false,
            ];
        }

        return $out;
    }

    /** An Entra account's usable address: its mailbox, else its sign-in name. */
    private static function addressOf(IdentityUser $account): ?string
    {
        foreach ([$account->mail, $account->user_principal_name] as $candidate) {
            $address = mb_strtolower(trim((string) $candidate));

            if ($address !== '' && str_contains($address, '@')) {
                return $address;
            }
        }

        return null;
    }

    /**
     * Letters and digits only, lower case — the same normalisation
     * LinkedAccountSuggester uses, so "Bander Al-Harbi" and "Bander Alharbi"
     * are one name while TestUserAVD1 and TestUserAVD2 stay apart.
     */
    private static function letters(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $value)) ?? '';
    }

    /**
     * The same normalisation in SQL, for matching a name inside a query.
     *
     * Only the characters that actually turn up in the employee names here —
     * spaces, hyphens, dots, apostrophes and underscores. Anything exotic simply
     * fails to match and the row is offered through the search box instead,
     * which is the safe direction to be wrong in.
     */
    private static function lettersSql(string $column): string
    {
        $expression = "LOWER({$column})";

        foreach ([' ', '-', '.', "''", '_', ','] as $character) {
            $expression = "REPLACE({$expression}, '{$character}', '')";
        }

        return $expression;
    }
}
