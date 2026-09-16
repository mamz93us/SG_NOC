<?php

namespace App\Services\Identity;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Which NOC employee an Azure account belongs to.
 *
 * Shared by the licence sync (LicenseAssignmentPlan) and the Microsoft licence
 * review report, so the report can never name a different owner than the one
 * the sync records licences against.
 *
 * The Azure object id decides whenever an employee carries it. Email is only
 * the fallback, and it never picks by row order: the old sync ran
 * `azure_id = ? OR email = ? OR email = ?` and took the first row, which put
 * one active employee's licences on her terminated record with the same
 * address. An email candidate already linked to a different Azure account
 * that still exists belongs to that account instead, and a blank email matches
 * nothing.
 */
final class AzureEmployeeMatcher
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $byAzure = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $byEmail = [];

    /** @var array<string, true> */
    private array $liveAzureIds;

    /**
     * @param  iterable<object|array<string, mixed>>  $employees  id, azure_id, email, status
     * @param  iterable<string>  $liveAzureIds  object ids of every account that exists in Azure
     */
    public function __construct(iterable $employees, iterable $liveAzureIds)
    {
        foreach ($employees as $e) {
            $e = $e instanceof Arrayable ? $e->toArray() : (array) $e;
            $e['id'] = (int) $e['id'];
            if (! empty($e['azure_id'])) {
                $this->byAzure[$e['azure_id']][] = $e;
            }
            $email = strtolower(trim((string) ($e['email'] ?? '')));
            if ($email !== '') {
                $this->byEmail[$email][$e['id']] = $e;
            }
        }

        $this->liveAzureIds = [];
        foreach ($liveAzureIds as $id) {
            $this->liveAzureIds[(string) $id] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $user  id, userPrincipalName, mail
     * @return array{0: ?int, 1: list<int>} the employee id, or null with the candidates that made it ambiguous
     */
    public function match(array $user): array
    {
        if (! empty($this->byAzure[$user['id']])) {
            $matches = $this->byAzure[$user['id']];
            usort($matches, fn ($a, $b) => [($a['status'] ?? '') !== 'active', $a['id']] <=> [($b['status'] ?? '') !== 'active', $b['id']]);

            return [$matches[0]['id'], []];
        }

        $candidates = [];
        foreach (['userPrincipalName', 'mail'] as $field) {
            $email = strtolower(trim((string) ($user[$field] ?? '')));
            foreach ($this->byEmail[$email] ?? [] as $id => $e) {
                $linkedElsewhere = ! empty($e['azure_id']) && $e['azure_id'] !== $user['id'] && isset($this->liveAzureIds[$e['azure_id']]);
                if (! $linkedElsewhere) {
                    $candidates[$id] = $e;
                }
            }
        }

        if (count($candidates) === 1) {
            return [array_key_first($candidates), []];
        }

        $active = array_filter($candidates, fn ($e) => ($e['status'] ?? '') === 'active');
        if (count($active) === 1) {
            return [array_key_first($active), []];
        }

        return [null, array_keys($candidates)];
    }
}
