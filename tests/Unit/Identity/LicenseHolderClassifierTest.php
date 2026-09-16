<?php

use App\Services\Identity\LicenseHolderClassifier;

/**
 * Which licensed accounts a real employee needs, as the Microsoft 365 License
 * Review report sorts them.
 *
 * The regression worth keeping: an SSS Egypt employee's samirgroup.com record
 * borrows the Oracle number from their sssegypt.com record, and scoring that
 * like the person's own made the cheap samirgroup.com account "main" and
 * flagged their real mailbox as the second account.
 */
const HOLDER_SKUS = [
    'sku-e1' => ['name' => 'Office 365 E1', 'paid' => true, 'cost' => 408.33, 'currency' => 'SAR'],
    'sku-bp' => ['name' => 'Microsoft 365 Business Premium', 'paid' => true, 'cost' => 876.13, 'currency' => 'SAR'],
    'sku-free' => ['name' => 'Microsoft Power Automate Free', 'paid' => false, 'cost' => null, 'currency' => null],
];

function holderAccount(string $id, string $upn, string $name, array $skus = ['sku-e1'], bool $enabled = true): array
{
    return ['id' => $id, 'userPrincipalName' => $upn, 'mail' => $upn, 'displayName' => $name, 'accountEnabled' => $enabled, 'skuIds' => $skus];
}

function holderEmployee(int $id, string $name, ?string $email, ?string $azureId, array $extra = []): array
{
    return array_merge([
        'id' => $id, 'name' => $name, 'email' => $email, 'azure_id' => $azureId, 'status' => 'active',
        'employee_type' => 'standard', 'oracle_emp_no' => null, 'linked_primary_employee_id' => null,
        'terminated_date' => null, 'branch' => 'JED',
    ], $extra);
}

function classifyHolders(array $accounts, array $employees, array $punches = []): array
{
    $rows = LicenseHolderClassifier::classify($accounts, HOLDER_SKUS, $employees, $punches);

    return array_column($rows, null, 'upn');
}

it('sorts a licensed employee in Oracle HR as a real employee and totals the cost per currency', function () {
    $rows = classifyHolders(
        [holderAccount('az-1', 'sara@samirgroup.com', 'Sara Adel', ['sku-e1', 'sku-bp', 'sku-free'])],
        [holderEmployee(1, 'Sara Adel', 'sara@samirgroup.com', 'az-1', ['oracle_emp_no' => '2875'])],
    );

    expect($rows['sara@samirgroup.com'])
        ->toMatchArray(['category' => 'real', 'oracle_emp_no' => '2875', 'free' => ['Microsoft Power Automate Free']])
        ->and($rows['sara@samirgroup.com']['costs'])->toBe(['SAR' => 408.33 + 876.13]);
});

it('leaves out accounts that hold only free licenses', function () {
    $rows = classifyHolders(
        [holderAccount('az-1', 'booking@samirgroup.com', 'Booking', ['sku-free'])],
        [holderEmployee(1, 'Booking', 'booking@samirgroup.com', 'az-1')],
    );

    expect($rows)->toBe([]);
});

it('puts termination before a disabled account, and a disabled account before a room name', function () {
    $rows = classifyHolders(
        [
            holderAccount('az-1', 'left@samirgroup.com', 'Left Person', enabled: false),
            holderAccount('az-2', 'meeting@samirgroup.com', 'Meeting', enabled: false),
        ],
        [
            holderEmployee(1, 'Left Person', 'left@samirgroup.com', 'az-1', ['status' => 'terminated', 'oracle_emp_no' => '100']),
            holderEmployee(2, 'Meeting', 'meeting@samirgroup.com', 'az-2'),
        ],
    );

    expect($rows['left@samirgroup.com']['category'])->toBe('terminated')
        ->and($rows['meeting@samirgroup.com']['category'])->toBe('disabled');
});

it('calls a room or app account with no Oracle number "not a person", but never someone in Oracle HR', function () {
    $rows = classifyHolders(
        [
            holderAccount('az-1', 'ConferenceRoom@samirgroup.com', 'Conference Room'),
            holderAccount('az-2', 'store.keeper@samirgroup.com', 'Store Keeper'),
        ],
        [
            holderEmployee(1, 'Conference Room', 'ConferenceRoom@samirgroup.com', 'az-1'),
            holderEmployee(2, 'Store Keeper', 'store.keeper@samirgroup.com', 'az-2', ['oracle_emp_no' => '4410']),
        ],
    );

    expect($rows['ConferenceRoom@samirgroup.com']['category'])->toBe('not_person')
        ->and($rows['store.keeper@samirgroup.com']['category'])->toBe('real');
});

it('counts an Oracle number held on a second NOC record for the same person', function () {
    // The Oracle HR import created a service record for someone who already had a mailbox record.
    $rows = classifyHolders(
        [holderAccount('az-1', 'ibrahim.syed@samirgroup.com', 'Ibrahim Syed')],
        [
            holderEmployee(1, 'Ibrahim Syed', 'ibrahim.syed@samirgroup.com', 'az-1'),
            holderEmployee(2, 'Ibrahim Syed', null, null, ['employee_type' => 'service', 'oracle_emp_no' => '2693']),
        ],
        [2 => '2026-09-16 07:58:00'],
    );

    expect($rows['ibrahim.syed@samirgroup.com'])
        ->toMatchArray(['category' => 'real', 'oracle_emp_no' => '2693', 'oracle_on_record' => 2, 'last_punch' => '2026-09-16 07:58:00']);
});

it('marks the borrowed-number mailbox as the second account, not the person\'s own', function () {
    $rows = classifyHolders(
        [
            holderAccount('az-sg', 'ahmed.salem@samirgroup.com', 'Ahmed Salem', ['sku-e1']),
            holderAccount('az-sss', 'ahmed.salem@sssegypt.com', 'Ahmed Salem', ['sku-bp']),
            holderAccount('az-x', 'someone.else@samirgroup.com', 'Someone Else'),
        ],
        [
            holderEmployee(94, 'Ahmed Salem', 'ahmed.salem@samirgroup.com', 'az-sg'),
            holderEmployee(95, 'Ahmed Salem', 'ahmed.salem@sssegypt.com', 'az-sss', ['oracle_emp_no' => '519']),
            holderEmployee(96, 'Someone Else', 'someone.else@samirgroup.com', 'az-x', ['oracle_emp_no' => '520']),
        ],
        [95 => '2026-09-14 08:02:00'],
    );

    expect($rows['ahmed.salem@sssegypt.com']['category'])->toBe('real')
        ->and($rows['ahmed.salem@samirgroup.com']['category'])->toBe('second')
        ->and($rows['ahmed.salem@samirgroup.com']['reason'])->toContain('ahmed.salem@sssegypt.com');
});

it('keeps two people who share a name apart when Oracle HR knows them as different people', function () {
    $rows = classifyHolders(
        [
            holderAccount('az-1', 'mohamed.ali@samirgroup.com', 'Mohamed Ali'),
            holderAccount('az-2', 'mohamed.ali@sssegypt.com', 'Mohamed Ali'),
        ],
        [
            holderEmployee(1, 'Mohamed Ali', 'mohamed.ali@samirgroup.com', 'az-1', ['oracle_emp_no' => '1200']),
            holderEmployee(2, 'Mohamed Ali', 'mohamed.ali@sssegypt.com', 'az-2', ['oracle_emp_no' => '301']),
        ],
    );

    expect(array_column($rows, 'category'))->toBe(['real', 'real']);
});

it('puts a named person with no Oracle record under "not in Oracle HR", and an unmatched account under no employee', function () {
    $rows = classifyHolders(
        [
            holderAccount('az-1', 'zina@oriana-sa.com', 'Zina Nabulsi'),
            holderAccount('az-2', 'nobody@samirgroup.com', 'Nobody Known'),
        ],
        [holderEmployee(1, 'Zina Nabulsi', 'zina@oriana-sa.com', 'az-1')],
    );

    expect($rows['zina@oriana-sa.com']['category'])->toBe('not_in_hr')
        ->and($rows['nobody@samirgroup.com']['category'])->toBe('no_employee')
        ->and($rows['nobody@samirgroup.com']['employee'])->toBeNull();
});

it('lists accounts that need action before real employees', function () {
    $rows = LicenseHolderClassifier::classify(
        [
            holderAccount('az-1', 'aaa@samirgroup.com', 'Aaa Real'),
            holderAccount('az-2', 'zzz@samirgroup.com', 'Zzz Disabled', enabled: false),
        ],
        HOLDER_SKUS,
        [
            holderEmployee(1, 'Aaa Real', 'aaa@samirgroup.com', 'az-1', ['oracle_emp_no' => '1']),
            holderEmployee(2, 'Zzz Disabled', 'zzz@samirgroup.com', 'az-2', ['oracle_emp_no' => '2']),
        ],
    );

    expect(array_column($rows, 'category'))->toBe(['disabled', 'real']);
});
