<?php

namespace App\Services\EmailMarketing;

use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles "dynamic" email_lists (those with a non-null auto_domain) against
 * the employees table. Membership of a dynamic list mirrors active employees
 * whose email ends in @{auto_domain}. EmployeeObserver calls syncEmployee()
 * for incremental updates; SyncDynamicListsCommand calls syncAll() for a
 * full periodic reconciliation that catches drift.
 */
class DynamicListSyncService
{
    /**
     * Full reconciliation across every dynamic list. Used by the scheduled
     * command and on first install.
     */
    public function syncAll(): array
    {
        $totals = ['lists' => 0, 'added' => 0, 'removed' => 0];

        foreach (EmailList::whereNotNull('auto_domain')->get() as $list) {
            $r = $this->syncList($list);
            $totals['lists']++;
            $totals['added'] += $r['added'];
            $totals['removed'] += $r['removed'];
        }

        return $totals;
    }

    /**
     * Reconcile one dynamic list: ensure the pivot exactly matches the set
     * of active employees whose email ends in @{$list->auto_domain}.
     */
    public function syncList(EmailList $list): array
    {
        if (! $list->isDynamic()) {
            return ['added' => 0, 'removed' => 0];
        }

        $domain = strtolower($list->auto_domain);

        $employees = Employee::active()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereRaw('LOWER(email) LIKE ?', ['%@'.$domain])
            ->get();

        $targetSubIds = [];
        foreach ($employees as $emp) {
            $sub = $this->upsertSubscriberForEmployee($emp);
            if ($sub) {
                $targetSubIds[$sub->id] = true;
            }
        }
        $targetSubIds = array_keys($targetSubIds);

        $currentSubIds = $list->subscribers()->pluck('email_subscribers.id')->all();

        $toAdd = array_diff($targetSubIds, $currentSubIds);
        $toRemove = array_diff($currentSubIds, $targetSubIds);

        $now = now();
        foreach ($toAdd as $id) {
            $list->subscribers()->attach($id, ['subscribed_at' => $now]);
        }

        if (! empty($toRemove)) {
            $list->subscribers()->detach($toRemove);
        }

        return ['added' => count($toAdd), 'removed' => count($toRemove)];
    }

    /**
     * Incremental sync for one employee. Iterates every dynamic list and
     * ensures the employee is attached iff currently eligible. When
     * $previousEmail is provided (because the email column changed),
     * the old subscriber row is also detached from any matching list.
     */
    public function syncEmployee(Employee $employee, ?string $previousEmail = null): void
    {
        $dynamicLists = EmailList::whereNotNull('auto_domain')->get();
        if ($dynamicLists->isEmpty()) {
            return;
        }

        $currentEmail = $this->normalizeEmail($employee->email);
        $previousEmail = $this->normalizeEmail($previousEmail);

        foreach ($dynamicLists as $list) {
            $domain = strtolower($list->auto_domain);

            // Detach the old subscriber if the email used to match this list.
            if ($previousEmail && $previousEmail !== $currentEmail
                && $this->emailMatchesDomain($previousEmail, $domain)) {
                $oldSub = EmailSubscriber::where('email', $previousEmail)->first();
                if ($oldSub) {
                    $list->subscribers()->detach($oldSub->id);
                }
            }

            $eligible = $currentEmail
                && $employee->status === 'active'
                && $this->emailMatchesDomain($currentEmail, $domain);

            if ($eligible) {
                $sub = $this->upsertSubscriberForEmployee($employee);
                if ($sub) {
                    $list->subscribers()->syncWithoutDetaching([
                        $sub->id => ['subscribed_at' => now()],
                    ]);
                }
            } elseif ($currentEmail) {
                // No longer eligible (terminated, on_leave, or domain doesn't match) —
                // detach from this list if currently attached.
                $sub = EmailSubscriber::where('email', $currentEmail)->first();
                if ($sub) {
                    $list->subscribers()->detach($sub->id);
                }
            }
        }
    }

    /**
     * Find or create an EmailSubscriber row for this employee. Returns null
     * when the employee has no usable email. Does not overwrite the status
     * of an existing subscriber (they may have unsubscribed) — the dispatcher
     * filters by status at send time.
     */
    private function upsertSubscriberForEmployee(Employee $employee): ?EmailSubscriber
    {
        $email = $this->normalizeEmail($employee->email);
        if (! $email) {
            return null;
        }

        [$first, $last] = $this->splitName($employee->name);

        return EmailSubscriber::firstOrCreate(
            ['email' => $email],
            [
                'first_name'   => $first,
                'last_name'    => $last,
                'status'       => 'subscribed',
                'source'       => 'employee',
                'confirmed_at' => now(),
            ]
        );
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : strtolower($email);
    }

    private function emailMatchesDomain(string $email, string $domain): bool
    {
        return str_ends_with($email, '@'.$domain);
    }

    /**
     * Split "Ahmed Al Sayed" → ["Ahmed", "Al Sayed"]. Single-word names go
     * into first_name with an empty last_name.
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return [null, null];
        }
        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], $parts[1] ?? null];
    }
}
