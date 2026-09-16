<?php

namespace App\Services\Identity;

use Illuminate\Contracts\Support\Arrayable;

/**
 * People the NOC holds more than once and has not linked yet: an SSS Egypt
 * employee's sssegypt.com record next to their samirgroup.com one, the
 * managing director's three mailboxes, or a service record the Oracle HR
 * import created for someone who already had a mailbox record. Pure: employee
 * rows and punch dates go in, suggested links come out.
 *
 * Records belong together when they share an email, a mailbox name on another
 * domain, a full name, or the same name in reverse order. Two records whose
 * Oracle HR numbers differ are two people, and the group is dropped. A group
 * needs one record Oracle HR or BioTime knows, which is what makes it a person
 * rather than two app accounts called "Application Notification".
 *
 * The main record is the HR one: its own Oracle number first, then punches,
 * then a mailbox. Every other record becomes a linked account of it.
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
     * @return list<array{primary: array<string, mixed>, secondaries: list<array<string, mixed>>, reasons: list<string>}>
     */
    public static function suggest(iterable $employees, array $lastPunch = []): array
    {
        $records = [];
        foreach ($employees as $e) {
            $e = $e instanceof Arrayable ? $e->toArray() : (array) $e;
            $e['id'] = (int) $e['id'];
            $records[$e['id']] = $e;
        }

        // Records already linked to a main record are settled; terminated ones are gone.
        $open = array_filter($records, fn ($e) => ($e['status'] ?? '') !== 'terminated' && empty($e['linked_primary_employee_id']));

        $keys = [];
        foreach ($open as $id => $e) {
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
            $words = preg_split('/[^a-z]+/', strtolower((string) ($e['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            if (count($words) >= 2) {
                sort($words);
                $keys['reversed:'.implode(' ', $words)][] = $id;
            }
        }

        $parent = [];
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
            // A mailbox name only counts across domains; on one domain it is the same email.
            if ($kind === 'mailbox' && count(array_unique(array_map(fn ($id) => strtolower(substr(strrchr((string) $open[$id]['email'], '@'), 1)), $ids))) < 2) {
                continue;
            }
            // Identical names are "Same name"; only a different word order is "reverse order".
            if ($kind === 'reversed' && count(array_unique(array_map(fn ($id) => self::letters($open[$id]['name'] ?? ''), $ids))) < 2) {
                continue;
            }
            for ($i = 1; $i < count($ids); $i++) {
                $a = $find($ids[0]);
                $b = $find($ids[$i]);
                if ($a !== $b) {
                    $parent[$b] = $a;
                }
            }
            foreach ($ids as $id) {
                $reasons[$id][$kind] = true;
            }
        }

        $groups = [];
        foreach (array_keys($open) as $id) {
            if (isset($parent[$id]) || isset($reasons[$id])) {
                $groups[$find($id)][] = $id;
            }
        }

        // A record an admin already made the main one keeps that role.
        $hasLinked = [];
        foreach ($records as $e) {
            if (! empty($e['linked_primary_employee_id'])) {
                $hasLinked[(int) $e['linked_primary_employee_id']] = true;
            }
        }

        $suggestions = [];
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $oracle = array_unique(array_filter(array_map(fn ($id) => trim((string) ($open[$id]['oracle_emp_no'] ?? '')), $ids)));
            if (count($oracle) > 1) {
                continue;
            }

            $score = fn ($id) => (isset($hasLinked[$id]) ? 16 : 0)
                + (trim((string) ($open[$id]['oracle_emp_no'] ?? '')) !== '' ? 8 : 0)
                + (isset($lastPunch[$id]) ? 4 : 0)
                + (! empty($open[$id]['azure_id']) ? 2 : 0)
                + (($open[$id]['employee_type'] ?? 'standard') === 'standard' ? 1 : 0);
            usort($ids, fn ($a, $b) => [$score($b), $a] <=> [$score($a), $b]);

            $primary = $ids[0];
            if ($score($primary) < 4) {
                continue; // Neither Oracle HR nor BioTime knows anyone here.
            }

            $groupReasons = [];
            foreach ($ids as $id) {
                $groupReasons += $reasons[$id] ?? [];
            }

            $suggestions[] = [
                'primary' => $open[$primary] + ['last_punch' => $lastPunch[$primary] ?? null],
                'secondaries' => array_map(fn ($id) => $open[$id] + ['last_punch' => $lastPunch[$id] ?? null], array_slice($ids, 1)),
                'reasons' => array_values(array_map(fn ($kind) => self::REASONS[$kind], array_keys(array_intersect_key(self::REASONS, $groupReasons)))),
            ];
        }

        usort($suggestions, fn ($a, $b) => strcasecmp((string) $a['primary']['name'], (string) $b['primary']['name']));

        return $suggestions;
    }

    private static function letters(?string $value): string
    {
        return (string) preg_replace('/[^a-z]/', '', strtolower((string) $value));
    }
}
