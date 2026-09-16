<?php

use App\Services\Identity\LinkedAccountSuggester;

/**
 * What the Linked Accounts page offers for people the NOC holds more than
 * once: merge a duplicate record into the person's record, or link a real second
 * mailbox to the person's main record. The cases are the ones found on NOC2 on
 * 2026-09-16.
 */
function suggestRecord(int $id, string $name, ?string $email, array $extra = []): array
{
    return array_merge([
        'id' => $id, 'name' => $name, 'email' => $email, 'azure_id' => $email ? "az-{$id}" : null,
        'status' => 'active', 'employee_type' => 'standard', 'oracle_emp_no' => null, 'linked_primary_employee_id' => null,
        'job_title' => null, 'branch' => null, 'department' => null,
    ], $extra);
}

/** Suggestions with every record's Azure account treated as live, unless listed as gone. */
function suggestFor(array $records, array $punches = [], array $goneAccounts = []): array
{
    $live = array_values(array_diff(array_filter(array_column($records, 'azure_id')), $goneAccounts));

    return LinkedAccountSuggester::suggest($records, $punches, $live);
}

function suggestionIds(array $suggestion): array
{
    return [$suggestion['kind'], $suggestion['primary']['id'], array_column($suggestion['secondaries'], 'id')];
}

it('merges the service record the Oracle HR import created into the person\'s mailbox record', function () {
    $suggestions = suggestFor([
        suggestRecord(285, 'Ibrahim Syed', 'ibrahim.syed@samirgroup.com'),
        suggestRecord(762, 'Ibrahim Syed', null, ['employee_type' => 'service', 'oracle_emp_no' => '2693']),
    ], [762 => '2026-09-16 07:58:00']);

    expect(array_map('suggestionIds', $suggestions))->toBe([['merge', 285, [762]]])
        ->and($suggestions[0]['reasons'])->toBe(['Same name']);
});

it('merges a record holding the same email into the one with the Microsoft account, even when someone linked them', function () {
    $suggestions = suggestFor([
        suggestRecord(690, 'Reema Al Mutairi', 'reema.almutairi@samirgroup.com', ['linked_primary_employee_id' => 805]),
        suggestRecord(805, 'Reema  Almutairi', 'reema.almutairi@samirgroup.com', ['oracle_emp_no' => '2875', 'azure_id' => null]),
    ], [805 => '2026-09-16 08:00:00']);

    expect(array_map('suggestionIds', $suggestions))->toBe([['merge', 690, [805]]])
        ->and($suggestions[0]['reasons'])->toBe(['Same email', 'Same name']);
});

it('merges an old terminated record into the current one, and leaves the mailbox linked to it for the merge to move', function () {
    $suggestions = suggestFor([
        suggestRecord(709, 'Rana Montaser', 'rana.montaser@sssegypt.com', ['status' => 'terminated']),
        suggestRecord(716, 'Rana Montaser', 'rana.montaser@sssegypt.com'),
        suggestRecord(717, 'Rana Montaser', 'rana.montaser@samirgroup.com', ['linked_primary_employee_id' => 709]),
        suggestRecord(625, 'Tariq Al Hindi', 'tariq.alhindi@samirgroup.com', ['status' => 'terminated']),
        suggestRecord(799, 'Tariq Alhindi', null, ['employee_type' => 'service', 'oracle_emp_no' => '2625']),
    ], [716 => '2026-09-16 08:00:00'], goneAccounts: ['az-709', 'az-625']);

    expect(array_map('suggestionIds', $suggestions))->toBe([
        ['merge', 716, [709]],
        ['merge', 799, [625]],
    ]);
});

it('links a second live mailbox to the HR record instead of merging it', function () {
    $suggestions = suggestFor([
        suggestRecord(100, 'Ahmed Yousef', 'ahmed.yousef@samirgroup.com'),
        suggestRecord(101, 'Ahmed Yousef', 'ahmed.yousef@sssegypt.com', ['oracle_emp_no' => '537']),
        // A different person with a reversed name and another Oracle number must not pull the group apart.
        suggestRecord(668, 'Yousef Ahmed', 'yousef.ahmed@sssegypt.com', ['oracle_emp_no' => '518']),
    ], [101 => '2026-09-14 08:00:00', 668 => '2026-09-14 08:00:00']);

    expect(array_map('suggestionIds', $suggestions))->toBe([['link', 101, [100]]])
        ->and($suggestions[0]['reasons'])->toBe(['Same mailbox name on another domain', 'Same name']);
});

it('keeps a record an admin already made the main one, and skips mailboxes already linked', function () {
    $suggestions = suggestFor([
        suggestRecord(10, 'Nada Khier', 'nada.khier@sssegypt.com'),
        suggestRecord(11, 'Nada Khier', 'nada.khier@samirgroup.com', ['linked_primary_employee_id' => 10]),
        suggestRecord(12, 'Nada Khier', 'nada@oriana-sa.com', ['oracle_emp_no' => '520']),
    ]);

    expect(array_map('suggestionIds', $suggestions))->toBe([['link', 10, [12]]]);
});

it('suggests nothing for different people, numbered test accounts or app accounts nobody in HR knows', function () {
    $suggestions = suggestFor([
        suggestRecord(1, 'Mohammed Qasim', 'mohammed.qasim@samirgroup.com', ['oracle_emp_no' => '2563']),
        suggestRecord(2, 'Mohammed Qasim', null, ['employee_type' => 'service', 'oracle_emp_no' => '1081']),
        suggestRecord(3, 'TestUserAVD1 Test', 'testuseravd1@samirgroup.com', ['oracle_emp_no' => '9001']),
        suggestRecord(4, 'TestUserAVD2 Test', 'testuseravd2@samirgroup.com'),
        suggestRecord(5, 'Application Notification', 'customapp@samirgroup.com'),
        suggestRecord(6, 'Application Notification', 'customapp1@samirgroup.com'),
        suggestRecord(7, 'Old Leaver', 'old.leaver@samirgroup.com', ['status' => 'terminated', 'oracle_emp_no' => '77']),
        suggestRecord(8, 'Old Leaver', 'old.leaver@sssegypt.com', ['status' => 'terminated']),
    ], goneAccounts: ['az-7', 'az-8']);

    expect($suggestions)->toBe([]);
});
