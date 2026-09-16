<?php

namespace App\Services\Identity;

use Illuminate\Contracts\Support\Arrayable;

/**
 * People the NOC holds more than once, and what to do about each. Pure:
 * employee rows, punch dates and the Azure accounts that exist go in, merge and
 * link suggestions come out.
 *
 * Records belong to one person when they share an email, a mailbox name on
 * another domain, a full name, or the same name in reverse order. Names keep
 * their digits, so TestUserAVD1 and TestUserAVD2 stay apart. A group never forms
 * across two different Oracle HR numbers: Oracle's SSS-Egypt and SamirGroup
 * series collide, and "Yousef Ahmed" is not "Ahmed Yousef".
 *
 * Within a group:
 * - **merge**: every record without a live Microsoft account is the same person
 *   recorded again, typically a service record the Oracle HR import created next
 *   to the mailbox record, or an old terminated record next to the current one.
 *   It merges into the record kept (see EmployeeMerger::order()).
 * - **link**: two or more live mailboxes (sssegypt.com and samirgroup.com) are
 *   real accounts; the extra ones link to the HR record.
 *
 * A group needs something that makes it a person: an Oracle number, punches, an
 * existing link, or, for a merge, a shared email.
 */
final class LinkedAccountSuggester
{
    public const REASONS = [
        'email' => 'Same email',
        'mailbox' => 'Same mailbox name on another domain',
        'name' => 'Same name',
        'reversed' => 'Same name in reverse order',
    ];

    /**
     * @param  iterable<object|array<string, mixed>>  $employees  id, name, email, azure_id, status, employee_type, oracle_emp_no, linked_primary_employee_id
     * @param  array<int, string>  $lastPunch  employee id => latest punch time
     * @param  iterable<string>  $liveAzureIds  object ids of the accounts that exist in Azure
     * @return list<array{kind: string, primary: array<string, mixed>, secondaries: list<array<string, mixed>>, reasons: list<string>}>
     */
    public static function suggest(iterable $employees, array $lastPunch = [], iterable $liveAzureIds = []): array
    {
        $records = [];
        foreach ($employees as $e) {
            $e = $e instanceof Arrayable ? $e->toArray() : (array) $e;
            $e['id'] = (int) $e['id'];
            $records[$e['id']] = $e;
        }
        $live = [];
        foreach ($liveAzureIds as $azureId) {
            $live[(string) $azureId] = true;
        }
        $hasLive = fn (array $e) => ! empty($e['azure_id']) && isset($live[$e['azure_id']]);
        $oracle = fn (array $e) => trim((string) ($e['oracle_emp_no'] ?? ''));

        $keys = [];
        foreach ($records as $id => $e) {
            $email = strtolower(trim((string) ($e['email'] ?? '')));
            if ($email !== '' && str_contains($email, '@')) {
                $keys['email:'.$email][] = $id;
                $local = self::letters(strstr($email, '@', true));
                if (strlen($local) >= 3) {
                    $keys['mailbox:'.$local][] = $id;
                }
            }
            $name = self::letters($e['name'] ?? '');
            if (strlen($name) >= 6) {
                $keys['name:'.$name][] = $id;
            }
            $words = preg_split('/[^a-z0-9]+/', strtolower((string) ($e['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            if (count($words) >= 2) {
                sort($words);
                $keys['reversed:'.implode(' ', $words)][] = $id;
            }
        }

        // Union-find that refuses to join two different Oracle numbers.
        $parent = [];
        $numbers = [];
        foreach ($records as $id => $e) {
            $numbers[$id] = $oracle($e);
        }
        $find = function (int $x) use (&$parent, &$find): int {
            if (($parent[$x] ?? $x) === $x) {
                return $x;
            }

            return $parent[$x] = $find($parent[$x]);
        };
        $reasons = [];
        foreach ($keys as $key => $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) < 2) {
                continue;
            }
            $kind = strstr($key, ':', true);
            if ($kind === 'mailbox' && count(array_unique(array_map(fn ($id) => strtolower(substr(strrchr((string) $records[$id]['email'], '@'), 1)), $ids))) < 2) {
                continue; // One domain means the same email, which has its own key.
            }
            if ($kind === 'reversed' && count(array_unique(array_map(fn ($id) => self::letters($records[$id]['name'] ?? ''), $ids))) < 2) {
                continue; // Identical names are "Same name".
            }
            foreach (array_slice($ids, 1) as $id) {
                $a = $find($ids[0]);
                $b = $find($id);
                if ($a !== $b) {
                    if ($numbers[$a] !== '' && $numbers[$b] !== '' && $numbers[$a] !== $numbers[$b]) {
                        continue;
                    }
                    $parent[$b] = $a;
                    $numbers[$a] = $numbers[$a] !== '' ? $numbers[$a] : $numbers[$b];
                }
                if ($kind !== 'reversed' || self::letters($records[$ids[0]]['name'] ?? '') !== self::letters($records[$id]['name'] ?? '')) {
                    $reasons[$ids[0]][$kind] = true;
                    $reasons[$id][$kind] = true;
                }
            }
        }

        $groups = [];
        foreach (array_keys($records) as $id) {
            $groups[$find($id)][] = $id;
        }

        $hasLinked = [];
        foreach ($records as $e) {
            if (! empty($e['linked_primary_employee_id'])) {
                $hasLinked[(int) $e['linked_primary_employee_id']] = true;
            }
        }
        $with = fn (int $id) => $records[$id] + ['last_punch' => $lastPunch[$id] ?? null, 'has_live_account' => $hasLive($records[$id])];

        $suggestions = [];
        foreach ($groups as $ids) {
            if (count($ids) < 2 || ! array_filter($ids, fn ($id) => ($records[$id]['status'] ?? '') !== 'terminated')) {
                continue;
            }
            $groupReasons = [];
            foreach ($ids as $id) {
                $groupReasons += $reasons[$id] ?? [];
            }
            $reasonText = array_values(array_map(fn ($kind) => self::REASONS[$kind], array_keys(array_intersect_key(self::REASONS, $groupReasons))));
            $known = (bool) array_filter($ids, fn ($id) => $oracle($records[$id]) !== '' || isset($lastPunch[$id]) || isset($hasLinked[$id]));

            // The mailbox that is the person's HR record: an existing main record, its own Oracle number, punches.
            $mailboxes = array_values(array_filter($ids, fn ($id) => $hasLive($records[$id])));
            $mainScore = fn ($id) => [($records[$id]['status'] ?? '') !== 'terminated' ? 1 : 0, isset($hasLinked[$id]) ? 1 : 0, $oracle($records[$id]) !== '' ? 1 : 0, isset($lastPunch[$id]) ? 1 : 0, -$id];
            usort($mailboxes, fn ($a, $b) => $mainScore($b) <=> $mainScore($a));

            // Merge: records without a live account fold into the main mailbox, or, with none, into the best of them.
            $others = array_values(array_filter($ids, fn ($id) => ! $hasLive($records[$id])));
            if ($others) {
                $keepRank = fn ($id) => [($records[$id]['status'] ?? '') !== 'terminated' ? 1 : 0, $oracle($records[$id]) !== '' ? 1 : 0, isset($lastPunch[$id]) ? 1 : 0, -$id];
                usort($others, fn ($a, $b) => $keepRank($b) <=> $keepRank($a));
                $keep = $mailboxes[0] ?? array_shift($others);
                $sameEmail = isset($groupReasons['email']);
                $current = fn ($id) => ($records[$id]['status'] ?? '') !== 'terminated';
                $email = fn ($id) => strtolower(trim((string) ($records[$id]['email'] ?? '')));
                // Same rules EmployeeMerger::problems() enforces: never a current record into a leaver, never two current addresses.
                $duplicates = array_values(array_filter($others, fn ($id) => ! (! $current($keep) && $current($id))
                    && ! ($current($keep) && $current($id) && $email($keep) !== '' && $email($id) !== '' && $email($keep) !== $email($id))));
                if ($duplicates && ($known || $sameEmail) && ($records[$keep]['status'] ?? '') !== 'terminated') {
                    $suggestions[] = [
                        'kind' => 'merge',
                        'primary' => $with($keep),
                        'secondaries' => array_map($with, $duplicates),
                        'reasons' => $reasonText,
                    ];
                }
            }

            // Link: extra live mailboxes of a current person, not yet linked to the main one.
            if (count($mailboxes) >= 2 && $known) {
                $main = $mailboxes[0];
                // Only unlinked mailboxes. One linked to a record that merges into the main one follows the merge.
                $toLink = array_values(array_filter(array_slice($mailboxes, 1), fn ($id) => ($records[$id]['status'] ?? '') !== 'terminated'
                    && empty($records[$id]['linked_primary_employee_id'])
                    && ! isset($hasLinked[$id])));
                if ($toLink && ($records[$main]['status'] ?? '') !== 'terminated' && empty($records[$main]['linked_primary_employee_id'])) {
                    $suggestions[] = [
                        'kind' => 'link',
                        'primary' => $with($main),
                        'secondaries' => array_map($with, $toLink),
                        'reasons' => $reasonText,
                    ];
                }
            }
        }

        usort($suggestions, fn ($a, $b) => [$a['kind'] === 'merge' ? 0 : 1, strtolower((string) $a['primary']['name'])]
            <=> [$b['kind'] === 'merge' ? 0 : 1, strtolower((string) $b['primary']['name'])]);

        return $suggestions;
    }

    private static function letters(?string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
    }
}
