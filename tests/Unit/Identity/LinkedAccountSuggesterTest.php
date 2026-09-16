<?php

use App\Services\Identity\LinkedAccountSuggester;

/**
 * Which records the Linked Accounts page offers to join into one person, and
 * which one it makes the main record.
 */
function suggestRecord(int $id, string $name, ?string $email, array $extra = []): array
{
    return array_merge([
        'id' => $id, 'name' => $name, 'email' => $email, 'azure_id' => $email ? "az-{$id}" : null,
        'status' => 'active', 'employee_type' => 'standard', 'oracle_emp_no' => null, 'linked_primary_employee_id' => null,
        'job_title' => null, 'branch' => null, 'department' => null,
    ], $extra);
}

it('makes the SSS Egypt record with the Oracle number the main one and links the samirgroup.com mailbox to it', function () {
    $suggestions = LinkedAccountSuggester::suggest([
        suggestRecord(94, 'Ahmed Salem', 'ahmed.salem@samirgroup.com'),
        suggestRecord(95, 'Ahmed Salem', 'ahmed.salem@sssegypt.com', ['oracle_emp_no' => '519']),
    ], [95 => '2026-09-14 08:02:00']);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['primary']['id'])->toBe(95)
        ->and(array_column($suggestions[0]['secondaries'], 'id'))->toBe([94])
        ->and($suggestions[0]['reasons'])->toBe(['Same mailbox name on another domain', 'Same name']);
});

it('makes the service record from the HR import the main one for a person who also has a mailbox record', function () {
    $suggestions = LinkedAccountSuggester::suggest([
        suggestRecord(285, 'Ibrahim Syed', 'ibrahim.syed@samirgroup.com'),
        suggestRecord(762, 'Ibrahim Syed', null, ['employee_type' => 'service', 'oracle_emp_no' => '2693']),
    ], [762 => '2026-09-16 07:58:00']);

    expect($suggestions[0]['primary']['id'])->toBe(762)
        ->and(array_column($suggestions[0]['secondaries'], 'id'))->toBe([285])
        ->and($suggestions[0]['reasons'])->toBe(['Same name']);
});

it('groups every mailbox of one person, and matches a name written in reverse order', function () {
    $suggestions = LinkedAccountSuggester::suggest([
        suggestRecord(566, 'Saher Al-Hindi', 'saher.alhindi@samirgroup.com', ['oracle_emp_no' => '1656']),
        suggestRecord(567, 'SAHER ALHINDI', 'saher@sssegypt.com'),
        suggestRecord(568, 'Saher Al Hindi', 'saher@oriana-sa.com'),
        suggestRecord(275, 'Hussein Moukalled', 'hussein.moukalled@samirgroup.com', ['oracle_emp_no' => '283']),
        suggestRecord(479, 'Moukalled Hussein', 'moukalled.hussein@samirgroup.com'),
    ]);

    expect($suggestions)->toHaveCount(2)
        ->and($suggestions[0]['primary']['id'])->toBe(275)
        ->and($suggestions[0]['reasons'])->toBe(['Same name in reverse order'])
        ->and($suggestions[1]['primary']['id'])->toBe(566)
        ->and(array_column($suggestions[1]['secondaries'], 'id'))->toBe([567, 568]);
});

it('never joins records Oracle HR knows as two different people', function () {
    $suggestions = LinkedAccountSuggester::suggest([
        suggestRecord(1, 'Mohamed Hassan', 'mohamed.hassan@samirgroup.com', ['oracle_emp_no' => '1200']),
        suggestRecord(2, 'Mohamed Hassan', 'mohamed.hassan@sssegypt.com', ['oracle_emp_no' => '524']),
    ]);

    expect($suggestions)->toBe([]);
});

it('skips app accounts nobody in HR or BioTime knows, terminated records and accounts already linked', function () {
    $suggestions = LinkedAccountSuggester::suggest([
        suggestRecord(1, 'Application Notification', 'customapp@samirgroup.com'),
        suggestRecord(2, 'Application Notification', 'customapp1@samirgroup.com'),
        suggestRecord(3, 'Marina Atef', 'marina.atef@sssegypt.com', ['oracle_emp_no' => '529']),
        suggestRecord(4, 'Marina Atef', 'marina.atef@samirgroup.com', ['status' => 'terminated']),
        suggestRecord(5, 'Sara Gad', 'sara.gad@sssegypt.com', ['oracle_emp_no' => '523']),
        suggestRecord(6, 'Sara Gad', 'sara.gad@samirgroup.com', ['linked_primary_employee_id' => 5]),
    ]);

    expect($suggestions)->toBe([]);
});

it('keeps a record an admin already made the main one as the main one', function () {
    $suggestions = LinkedAccountSuggester::suggest([
        suggestRecord(10, 'Nada Khier', 'nada.khier@sssegypt.com'),
        suggestRecord(11, 'Nada Khier', 'nada.khier@samirgroup.com', ['linked_primary_employee_id' => 10]),
        suggestRecord(12, 'Nada Khier', 'nada@oriana-sa.com', ['oracle_emp_no' => '520']),
    ]);

    expect($suggestions[0]['primary']['id'])->toBe(10)
        ->and(array_column($suggestions[0]['secondaries'], 'id'))->toBe([12]);
});
