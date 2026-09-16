<?php

namespace App\Services\Identity;

/**
 * Sorts every Azure account holding a paid Microsoft licence by whether a real
 * employee needs it. Pure: accounts, SKUs, employees and punch dates go in,
 * one row per licensed account comes out.
 *
 * A matching employee record is not proof of a person. The employee list was
 * seeded from Entra, so meeting rooms, the scanner and app accounts are
 * "employees" too; the Oracle HR number is what separates people. When the
 * number sits on a second NOC record for the same person (the Oracle HR import
 * created service records for people who already had a mailbox record), it
 * still counts, found by email, name, or the name in reverse order, and only
 * when exactly one record matches.
 *
 * First match wins: no employee, terminated, sign-in disabled, not a person,
 * second account, not in Oracle HR, real employee.
 */
final class LicenseHolderClassifier
{
    public const CATEGORIES = [
        'real' => [
            'label' => 'Real employee',
            'title' => 'Real employees in Oracle HR',
            'hint' => 'These need their licence.',
            'color' => 'success',
        ],
        'not_in_hr' => [
            'label' => 'Not in Oracle HR',
            'title' => 'Real names with no Oracle record',
            'hint' => 'Owners, sister-company staff, contractors, new joiners, or leavers HR never recorded. HR should confirm they still work here.',
            'color' => 'info',
        ],
        'not_person' => [
            'label' => 'Not a person',
            'title' => 'Not a person',
            'hint' => 'Meeting rooms, scanner, fax, test and app notification accounts.',
            'color' => 'warning',
        ],
        'second' => [
            'label' => 'Second account',
            'title' => 'Second account of someone already licensed',
            'hint' => 'The same person also holds paid licences on another account.',
            'color' => 'warning',
        ],
        'disabled' => [
            'label' => 'Disabled in Azure',
            'title' => 'Disabled in Azure but still licensed',
            'hint' => 'Nobody can sign in, but the licences are still paid for.',
            'color' => 'danger',
        ],
        'terminated' => [
            'label' => 'Terminated',
            'title' => 'Terminated in the NOC but still licensed',
            'hint' => 'The employee has left and the account still holds paid licences.',
            'color' => 'danger',
        ],
        'no_employee' => [
            'label' => 'No employee record',
            'title' => 'No NOC employee record',
            'hint' => 'No employee matches the account. Find its owner.',
            'color' => 'secondary',
        ],
    ];

    /** Names that mark a room, device, service or test account, never a person. */
    private const NOT_A_PERSON_WORDS = [
        'test', 'room', 'meeting', 'meetingroom', 'conference', 'conferenceroom', 'groundfloormeetingroom', 'scanner', 'scan',
        'support', 'notify', 'notification', 'samircrmnotification', 'noreply', 'info', 'career', 'careers', 'library',
        'ipad', 'avlcipad', 'appletv', 'cam', 'sgcam', 'voip', 'fax', 'spsfax', 'mycalls', 'calls', 'docs', 'attachment',
        'integration', 'customapp', 'customapp1', 'invoice', 'e-invoice', 'subscription', 'onboarding', 'it_onboarding',
        'it_end_of_service_action', 'sophos', 'oraclocal', 'ubl', 'store', 'engineering', 'admin', 'helpdesk', 'reception',
        'new_user', 'metadev', 'crm', 'mailbox', 'shared', 'alerts', 'backup', 'printer',
    ];

    /**
     * @param  list<array{id: string, userPrincipalName: string, mail: ?string, displayName: ?string, accountEnabled: bool, skuIds: list<string>}>  $accounts
     * @param  array<string, array{name: string, paid: bool, cost: ?float, currency: ?string}>  $skus  keyed by SKU id
     * @param  iterable<object|array<string, mixed>>  $employees  id, name, email, azure_id, status, employee_type, oracle_emp_no, linked_primary_employee_id
     * @param  array<int, string>  $lastPunch  employee id => latest punch time
     * @return list<array<string, mixed>>
     */
    public static function classify(array $accounts, array $skus, iterable $employees, array $lastPunch = []): array
    {
        $emps = [];
        foreach ($employees as $e) {
            $e = (array) $e;
            $e['id'] = (int) $e['id'];
            $emps[$e['id']] = $e;
        }
        $matcher = new AzureEmployeeMatcher($emps, array_column($accounts, 'id'));
        $oracleIndex = self::oracleIndex($emps);

        $rows = [];
        foreach ($accounts as $account) {
            $paid = [];
            $free = [];
            foreach (array_unique($account['skuIds']) as $skuId) {
                if (! isset($skus[$skuId])) {
                    continue;
                }
                if ($skus[$skuId]['paid']) {
                    $paid[$skuId] = $skus[$skuId];
                } else {
                    $free[] = $skus[$skuId]['name'];
                }
            }
            if ($paid === []) {
                continue;
            }

            [$employeeId] = $matcher->match($account);
            $employee = $employeeId ? $emps[$employeeId] : null;
            $twin = $employee ? self::twin($employee, $account, $emps, $oracleIndex) : null;
            $oracle = $employee ? ($employee['oracle_emp_no'] ?: ($twin['oracle_emp_no'] ?? null)) : null;
            $punch = $employee ? max((string) ($lastPunch[$employee['id']] ?? ''), (string) ($twin ? ($lastPunch[$twin['id']] ?? '') : '')) : '';

            $costs = [];
            foreach ($paid as $sku) {
                if ($sku['cost'] !== null) {
                    $currency = $sku['currency'] ?: 'USD';
                    $costs[$currency] = ($costs[$currency] ?? 0) + $sku['cost'];
                }
            }

            $rows[$account['id']] = [
                'azure_id' => $account['id'],
                'upn' => $account['userPrincipalName'],
                'display_name' => $account['displayName'] ?? $account['userPrincipalName'],
                'enabled' => (bool) $account['accountEnabled'],
                'employee' => $employee,
                'oracle_emp_no' => $oracle ?: null,
                'oracle_on_record' => ($employee && ! $employee['oracle_emp_no'] && $twin) ? $twin['id'] : null,
                'last_punch' => $punch !== '' ? $punch : null,
                'punches_on_record' => $employee && isset($lastPunch[$employee['id']]),
                'not_a_person_word' => self::notAPersonWord($account),
                'paid' => array_values(array_map(fn ($s) => ['name' => $s['name'], 'cost' => $s['cost'], 'currency' => $s['currency']], $paid)),
                'free' => $free,
                'costs' => $costs,
                'other_accounts' => [],
                'main_account' => true,
            ];
        }

        self::markSecondAccounts($rows);

        foreach ($rows as &$row) {
            [$row['category'], $row['reason']] = self::categorise($row);
        }
        unset($row);

        // Accounts that need action first; real employees, the long tail, last.
        $order = array_flip(['terminated', 'disabled', 'not_person', 'second', 'not_in_hr', 'no_employee', 'real']);
        uasort($rows, fn ($a, $b) => [$order[$a['category']], strtolower($a['upn'])] <=> [$order[$b['category']], strtolower($b['upn'])]);

        return array_values($rows);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function categorise(array $row): array
    {
        $e = $row['employee'];

        if (! $e) {
            return ['no_employee', 'No NOC employee matches this account by Azure ID or email.'];
        }
        if (($e['status'] ?? '') === 'terminated') {
            $left = ! empty($e['terminated_date']) ? ' on '.substr((string) $e['terminated_date'], 0, 10) : '';

            return ['terminated', "{$e['name']} was terminated in the NOC{$left}, but the account still holds paid licences."];
        }
        if (! $row['enabled']) {
            return ['disabled', 'Sign-in is blocked in Azure, but the licences are still assigned. A shared mailbox needs no licence unless it is over 50 GB or on hold.'];
        }
        if (! $row['oracle_emp_no'] && $row['not_a_person_word']) {
            return ['not_person', "No Oracle HR number, and the name marks a room, device, service or test account (\"{$row['not_a_person_word']}\")."];
        }
        if (! $row['main_account']) {
            return ['second', 'The same person also holds paid licences on '.implode(', ', $row['other_accounts']).'.'];
        }
        if (! $row['oracle_emp_no']) {
            return ['not_in_hr', 'Active in the NOC but has no Oracle HR number. HR should confirm this person still works here.'];
        }
        if ($row['oracle_on_record']) {
            return ['real', "Oracle HR number {$row['oracle_emp_no']} is on a second NOC record for this person (#{$row['oracle_on_record']}); those two records should be merged or linked."];
        }

        return ['real', "Active employee with Oracle HR number {$row['oracle_emp_no']}."];
    }

    /**
     * Accounts that belong to one person: the same mailbox name on another
     * domain, the same display name, or records linked in the NOC. Two accounts
     * whose people carry different Oracle numbers are two people, unless the NOC
     * links them.
     *
     * The main account is the enabled one whose own NOC record holds the Oracle
     * number and the punches. A number or punches borrowed from another record
     * count for far less: an SSS Egypt employee's samirgroup.com record finds
     * the Oracle number on the sssegypt.com record, and scoring the two equally
     * made the cheap samirgroup.com account "main" and flagged the person's real
     * mailbox as the extra one.
     */
    private static function markSecondAccounts(array &$rows): void
    {
        $squash = fn (?string $s) => preg_replace('/[^a-z]/', '', strtolower((string) $s));

        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            if (($parent[$x] ?? $x) === $x) {
                return $x;
            }

            return $parent[$x] = $find($parent[$x]);
        };

        $byKey = [];
        foreach ($rows as $id => $row) {
            $local = $squash(strstr($row['upn'], '@', true) ?: $row['upn']);
            $name = $squash($row['display_name']);
            if (strlen($local) >= 3) {
                $byKey['local:'.$local][] = $id;
            }
            if (strlen($name) >= 3) {
                $byKey['name:'.$name][] = $id;
            }
            if ($row['employee']) {
                $byKey['emp:'.$row['employee']['id']][] = $id;
                if (! empty($row['employee']['linked_primary_employee_id'])) {
                    $byKey['emp:'.$row['employee']['linked_primary_employee_id']][] = $id;
                }
            }
        }
        foreach ($byKey as $ids) {
            $ids = array_values(array_unique($ids));
            for ($i = 1; $i < count($ids); $i++) {
                $a = $find($ids[0]);
                $b = $find($ids[$i]);
                if ($a !== $b) {
                    $parent[$b] = $a;
                }
            }
        }

        $groups = [];
        foreach (array_keys($rows) as $id) {
            $groups[$find($id)][] = $id;
        }

        $domains = array_count_values(array_map(fn ($r) => strtolower(substr(strrchr($r['upn'], '@') ?: '', 1)), $rows));
        arsort($domains);
        $homeDomain = (string) array_key_first($domains);

        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $oracle = array_unique(array_filter(array_map(fn ($id) => $rows[$id]['oracle_emp_no'], $ids)));
            $linked = (bool) array_filter($ids, fn ($id) => ! empty($rows[$id]['employee']['linked_primary_employee_id']));
            if (count($oracle) > 1 && ! $linked) {
                continue;
            }

            $score = fn ($id) => ($rows[$id]['enabled'] ? 32 : 0)
                + (! empty($rows[$id]['employee']['oracle_emp_no']) ? 16 : 0)
                + ($rows[$id]['punches_on_record'] ? 8 : 0)
                + ($rows[$id]['oracle_emp_no'] ? 4 : 0)
                + (empty($rows[$id]['employee']['linked_primary_employee_id']) ? 2 : 0)
                + (str_ends_with(strtolower($rows[$id]['upn']), '@'.$homeDomain) ? 1 : 0);
            usort($ids, fn ($a, $b) => [$score($b), strtolower($rows[$a]['upn'])] <=> [$score($a), strtolower($rows[$b]['upn'])]);

            foreach ($ids as $position => $id) {
                $rows[$id]['main_account'] = $position === 0;
                $rows[$id]['other_accounts'] = array_values(array_map(fn ($other) => $rows[$other]['upn'], array_filter($ids, fn ($other) => $other !== $id)));
            }
        }
    }

    /** @return array<string, array<string, list<int>>> */
    private static function oracleIndex(array $emps): array
    {
        $index = ['email' => [], 'name' => [], 'reversed' => []];
        foreach ($emps as $e) {
            if (empty($e['oracle_emp_no']) || ($e['status'] ?? '') === 'terminated') {
                continue;
            }
            if (! empty($e['email'])) {
                $index['email'][strtolower($e['email'])][] = $e['id'];
            }
            $index['name'][self::squashName($e['name'])][] = $e['id'];
            $index['reversed'][self::sortedName($e['name'])][] = $e['id'];
        }

        return $index;
    }

    /** The one other employee record carrying this person's Oracle number, if exactly one does. */
    private static function twin(array $employee, array $account, array $emps, array $index): ?array
    {
        if (! empty($employee['oracle_emp_no'])) {
            return null;
        }

        $probes = [
            'email' => [strtolower((string) $employee['email']), strtolower((string) $account['userPrincipalName'])],
            'name' => [self::squashName($employee['name']), self::squashName($account['displayName'] ?? '')],
            'reversed' => [self::sortedName($employee['name']), self::sortedName($account['displayName'] ?? '')],
        ];
        foreach ($probes as $kind => $keys) {
            $ids = [];
            foreach (array_unique(array_filter($keys)) as $key) {
                foreach ($index[$kind][$key] ?? [] as $id) {
                    if ($id !== $employee['id']) {
                        $ids[$id] = true;
                    }
                }
            }
            if (count($ids) === 1) {
                return $emps[array_key_first($ids)];
            }
        }

        return null;
    }

    private static function notAPersonWord(array $account): ?string
    {
        $local = strtolower(strstr($account['userPrincipalName'], '@', true) ?: $account['userPrincipalName']);
        $words = array_merge(
            [$local],
            preg_split('/[._\-]+/', $local),
            preg_split('/[^a-z0-9\-]+/', strtolower((string) ($account['displayName'] ?? '')))
        );
        foreach ($words as $word) {
            if ($word !== '' && in_array($word, self::NOT_A_PERSON_WORDS, true)) {
                return $word;
            }
        }

        return null;
    }

    private static function squashName(?string $name): string
    {
        return (string) preg_replace('/[^a-z]/', '', strtolower((string) $name));
    }

    private static function sortedName(?string $name): string
    {
        $words = preg_split('/[^a-z]+/', strtolower((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        sort($words);

        return implode(' ', $words);
    }
}
