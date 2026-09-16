<?php

use App\Services\Identity\LicenseAssignmentPlan;

/**
 * Which licence rows the NOC must add or remove so that it matches Microsoft.
 *
 * The cases here are the ones production got wrong under the old sync: rows the
 * NOC created itself never left, leavers whose Azure account was deleted kept
 * their licences for good, and `azure_id = ? OR email = ?` put one employee's
 * licences on her terminated record with the same address.
 */
const SKU_E1 = '18181a46-0d4e-45cd-891e-60aabd171b4e';
const SKU_EMS = 'efccb6f7-5641-4e0e-bd10-b4976e1bf68e';
const SKU_UNLINKED = '1f2f344a-700d-42c9-9427-5cea1d5d7ba6';

function planUser(string $id, string $upn, array $skus, array $extra = []): array
{
    return array_merge([
        'id' => $id,
        'userPrincipalName' => $upn,
        'mail' => $upn,
        'assignedLicenses' => array_map(fn ($sku) => ['skuId' => $sku, 'disabledPlans' => []], $skus),
        'licenseAssignmentStates' => [],
    ], $extra);
}

function planEmployee(int $id, ?string $azureId, ?string $email, string $status = 'active'): array
{
    return ['id' => $id, 'azure_id' => $azureId, 'email' => $email, 'status' => $status];
}

function buildPlan(array $users, array $employees, array $rows = []): LicenseAssignmentPlan
{
    return LicenseAssignmentPlan::build($users, [SKU_E1 => 10, SKU_EMS => 11], $employees, $rows, '2026-09-16');
}

it('adds a row for every linked licence Microsoft assigns, dated when Microsoft applied it', function () {
    $plan = buildPlan(
        [planUser('az-1', 'sara@samirgroup.com', [SKU_E1, SKU_EMS, SKU_UNLINKED], [
            'licenseAssignmentStates' => [['skuId' => SKU_E1, 'lastUpdatedDateTime' => '2026-08-29T14:25:32Z', 'state' => 'Active']],
        ])],
        [planEmployee(1, 'az-1', 'sara@samirgroup.com')],
    );

    expect($plan->add)->toHaveCount(2)
        ->and($plan->add[0])->toMatchArray(['license_id' => 10, 'employee_id' => 1, 'assigned_date' => '2026-08-29'])
        ->and($plan->add[1])->toMatchArray(['license_id' => 11, 'employee_id' => 1, 'assigned_date' => '2026-09-16'])
        ->and($plan->remove)->toBe([]);
});

it('leaves rows that already match alone', function () {
    $plan = buildPlan(
        [planUser('az-1', 'sara@samirgroup.com', [SKU_E1])],
        [planEmployee(1, 'az-1', 'sara@samirgroup.com')],
        [['id' => 500, 'license_id' => 10, 'assignable_id' => 1]],
    );

    expect($plan->add)->toBe([])
        ->and($plan->remove)->toBe([])
        ->and($plan->current)->toBe(1)
        ->and($plan->desired)->toBe(1);
});

it('removes a row Microsoft no longer assigns, whoever created it', function () {
    // Row 501 was made by the NOC's own Assign button ("Auto-assigned via Graph API").
    $plan = buildPlan(
        [planUser('az-1', 'sara@samirgroup.com', [SKU_E1])],
        [planEmployee(1, 'az-1', 'sara@samirgroup.com')],
        [
            ['id' => 500, 'license_id' => 10, 'assignable_id' => 1],
            ['id' => 501, 'license_id' => 11, 'assignable_id' => 1],
        ],
    );

    expect($plan->remove)->toBe([501])->and($plan->add)->toBe([]);
});

it('removes the licences of an employee whose Azure account was deleted', function () {
    $plan = buildPlan(
        [planUser('az-1', 'sara@samirgroup.com', [SKU_E1])],
        [
            planEmployee(1, 'az-1', 'sara@samirgroup.com'),
            planEmployee(2, 'az-gone', 'left@samirgroup.com', 'terminated'),
        ],
        [
            ['id' => 500, 'license_id' => 10, 'assignable_id' => 1],
            ['id' => 600, 'license_id' => 10, 'assignable_id' => 2],
            ['id' => 601, 'license_id' => 11, 'assignable_id' => 2],
        ],
    );

    expect($plan->remove)->toBe([600, 601]);
});

it('matches on the Azure id before email, so a terminated record with the same address gets nothing', function () {
    // Production: the terminated record has the lower id, and the old query picked it.
    $plan = buildPlan(
        [planUser('az-new', 'rana@sssegypt.com', [SKU_E1])],
        [
            planEmployee(709, 'az-old-deleted', 'rana@sssegypt.com', 'terminated'),
            planEmployee(716, 'az-new', 'rana@sssegypt.com'),
        ],
        [['id' => 900, 'license_id' => 10, 'assignable_id' => 709]],
    );

    expect($plan->add)->toHaveCount(1)
        ->and($plan->add[0]['employee_id'])->toBe(716)
        ->and($plan->remove)->toBe([900]);
});

it('falls back to email, preferring the one active record', function () {
    $plan = buildPlan(
        [planUser('az-1', 'Omar@SamirGroup.com', [SKU_E1])],
        [
            planEmployee(3, null, 'omar@samirgroup.com', 'terminated'),
            planEmployee(4, null, 'omar@samirgroup.com'),
        ],
    );

    expect($plan->add[0]['employee_id'])->toBe(4)->and($plan->ambiguous)->toBe([]);
});

it('reports two active records with the address as ambiguous instead of guessing', function () {
    $plan = buildPlan(
        [planUser('az-1', 'omar@samirgroup.com', [SKU_E1])],
        [
            planEmployee(3, null, 'omar@samirgroup.com'),
            planEmployee(4, null, 'omar@samirgroup.com'),
        ],
    );

    expect($plan->add)->toBe([])
        ->and($plan->ambiguous)->toBe(['omar@samirgroup.com' => [3, 4]]);
});

it('does not take an email match that belongs to another live Azure account', function () {
    $plan = buildPlan(
        [
            planUser('az-1', 'shared@samirgroup.com', [SKU_E1]),
            planUser('az-2', 'omar@samirgroup.com', [], ['mail' => 'shared@samirgroup.com']),
        ],
        [planEmployee(5, 'az-2', 'shared@samirgroup.com')],
    );

    expect($plan->add)->toBe([])->and($plan->unmatched)->toBe(['shared@samirgroup.com']);
});

it('never matches an account without mail to employees without email', function () {
    // The old query's orWhere('email', null) became "OR email IS NULL".
    $plan = buildPlan(
        [planUser('az-1', 'device@samirgroup.com', [SKU_E1], ['mail' => null])],
        [planEmployee(8, null, null), planEmployee(9, null, '')],
    );

    expect($plan->add)->toBe([])->and($plan->unmatched)->toBe(['device@samirgroup.com']);
});

it('removes the newer copy of a licence recorded twice for one employee', function () {
    $plan = buildPlan(
        [planUser('az-1', 'sara@samirgroup.com', [SKU_E1])],
        [planEmployee(1, 'az-1', 'sara@samirgroup.com')],
        [
            ['id' => 720, 'license_id' => 10, 'assignable_id' => 1],
            ['id' => 700, 'license_id' => 10, 'assignable_id' => 1],
        ],
    );

    expect($plan->remove)->toBe([720])->and($plan->add)->toBe([]);
});
