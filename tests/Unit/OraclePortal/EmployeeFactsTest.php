<?php

use App\Services\OraclePortal\EmployeeFacts;
use Carbon\CarbonImmutable;

/**
 * Oracle's /employees list onto the HR import's staged row shape, as the API
 * document of 2026-09-27 describes it and production answered on 2026-09-29.
 *
 * The hire-date cases are the reason this class exists separately from the
 * vacation importer's date parser, which bounds years to 2000-2100. That is
 * right for leave and wrong here: 21 of the 617 people in this feed were hired
 * before 2000, the oldest in 1988.
 */
it('maps an employee row onto the staged shape', function () {
    $row = EmployeeFacts::row([
        'personNumber' => '1003',
        'personId' => '100000000367262',
        'personName' => 'Jaison Joseph',
        'personNameAr' => 'جيسون جوزيف',
        'gender' => 'M',
        'department' => 'Customer Equipment Services Copiers - Riyadh',
        'supervisorName' => 'Wilbert Reyes',
        'managerName' => 'Alaa Ghonim',
        'locationName' => 'Riyadh',
        'jobName' => 'Technician',
        'personEmail' => 'jaison.joseph@samirgroup.com',
        'phone' => '0551234567',
        'startDate' => '2009-10-17',
        'status' => 'Active',
    ], 1);

    expect($row)->toMatchArray([
        'emp_no' => '1003',
        'emp_name' => 'Jaison Joseph',
        'email' => 'jaison.joseph@samirgroup.com',
        'mobile_no' => '0551234567',
        'dept_name' => 'Customer Equipment Services Copiers - Riyadh',
        'location_name' => 'Riyadh',
        'job_name' => 'Technician',
        'gender' => 'male',
        'person_id' => '100000000367262',
        'person_name_ar' => 'جيسون جوزيف',
        'assignment_status' => 'ACTIVE',
        'supervisor_name' => 'Wilbert Reyes',
        'manager_name' => 'Alaa Ghonim',
        'hire_date' => '2009-10-17',
    ]);
});

it('sends no dept no, because the API has none', function () {
    // writeToEmployee() only writes a non-empty DEPT NO, so an API pull never
    // blanks the one the spreadsheet set.
    expect(EmployeeFacts::row(['personNumber' => '1003'], 1)['dept_no'])->toBe('');
});

it('stages the phone raw, for normalizeMobile to judge', function () {
    expect(EmployeeFacts::row(['personNumber' => '1', 'phone' => '966551234567'], 1)['mobile_no'])
        ->toBe('966551234567');
});

it('reads Oracle\'s "-" as no phone at all', function () {
    // 25 people carry a bare dash. Staged as it stands, each would be reported
    // as an unrecognised mobile format on every pull.
    expect(EmployeeFacts::row(['personNumber' => '1', 'phone' => '-'], 1)['mobile_no'])->toBe('');
    expect(EmployeeFacts::row(['personNumber' => '1'], 1)['mobile_no'])->toBe('');
});

it('carries none of the columns only the retired /attendance view had', function () {
    // writeToEmployee() writes only non-empty values, so leaving these out
    // keeps what the last /attendance pull recorded.
    $row = EmployeeFacts::row(['personNumber' => '1', 'employeeCategory' => 'SALES', 'personType' => 'X'], 1);

    expect($row)->not->toHaveKeys(['employee_category', 'person_type', 'assignment_id']);
});

it('refuses a row with no person number', function () {
    expect(EmployeeFacts::row(['personName' => 'Nobody'], 1))->toBeNull();
    expect(EmployeeFacts::row(['personNumber' => '  '], 1))->toBeNull();
});

it('skips unusable rows when mapping a payload', function () {
    $rows = EmployeeFacts::rows([
        ['personNumber' => '1'],
        ['personName' => 'no number'],
        ['personNumber' => '2'],
    ]);

    expect($rows)->toHaveCount(2);
    expect(array_column($rows, 'emp_no'))->toBe(['1', '2']);
});

// ─── Hire dates ───────────────────────────────────────────────────

it('parses the yyyy-MM-dd hire date the API sends', function () {
    expect(EmployeeFacts::hireDate('2009-10-17')?->toDateString())->toBe('2009-10-17');
    expect(EmployeeFacts::hireDate('2026-01-01')?->toDateString())->toBe('2026-01-01');
});

it('parses a hire date from before 2000, which the leave parser rejects', function () {
    expect(EmployeeFacts::hireDate('1988-01-02')?->toDateString())->toBe('1988-01-02');
    expect(EmployeeFacts::hireDate('1995-08-09')?->toDateString())->toBe('1995-08-09');
});

it('still reads the dd-MMM-yy the retired view sent', function () {
    // PHP reads a two-digit year as 1970-1999 for 70-99, exactly right across
    // the feed's 1988-2026.
    expect(EmployeeFacts::hireDate('17-OCT-09')?->toDateString())->toBe('2009-10-17');
    expect(EmployeeFacts::hireDate('02-JAN-88')?->toDateString())->toBe('1988-01-02');
});

it('refuses a date before anybody could have been hired', function () {
    expect(EmployeeFacts::hireDate('1955-01-01'))->toBeNull();
    expect(EmployeeFacts::hireDate('01-JAN-55'))->toBeNull();
});

it('refuses a date far in the future, which is a typo not a start date', function () {
    $now = CarbonImmutable::parse('2026-09-20');

    expect(EmployeeFacts::hireDate('2040-01-01', $now))->toBeNull();
    // A start date next month is perfectly normal, though.
    expect(EmployeeFacts::hireDate('2026-11-01', $now)?->toDateString())->toBe('2026-11-01');
});

it('refuses a day that does not exist rather than rolling it over', function () {
    // Carbon's default is to overflow: 2020-02-31 becomes 2 March. A hire date
    // two days from what the feed said is worse than no hire date.
    expect(EmployeeFacts::hireDate('2020-02-31'))->toBeNull();
    expect(EmployeeFacts::hireDate('31-APR-24'))->toBeNull();
    // A real 29 February still works.
    expect(EmployeeFacts::hireDate('2024-02-29')?->toDateString())->toBe('2024-02-29');
});

it('returns null rather than throwing on an unreadable date', function () {
    // One odd row must not abort a pull of the whole workforce.
    expect(EmployeeFacts::hireDate('2026-9-17'))->toBeNull();
    expect(EmployeeFacts::hireDate('17/09/2026'))->toBeNull();
    expect(EmployeeFacts::hireDate(''))->toBeNull();
    expect(EmployeeFacts::hireDate(null))->toBeNull();
    expect(EmployeeFacts::hireDate('not a date'))->toBeNull();
});

// ─── Status ───────────────────────────────────────────────────────

it('treats only an explicit Inactive as inactive', function () {
    expect(EmployeeFacts::isInactive('INACTIVE'))->toBeTrue();
    expect(EmployeeFacts::isInactive('Inactive'))->toBeTrue();
    expect(EmployeeFacts::isInactive(' inactive '))->toBeTrue();

    expect(EmployeeFacts::isInactive('Active'))->toBeFalse();
    // Absence is not a statement. A person Oracle does not mention at all —
    // every SSS Egypt employee, anyone who left over a month ago — has no
    // status here, and that must never read as "has left".
    expect(EmployeeFacts::isInactive(null))->toBeFalse();
    expect(EmployeeFacts::isInactive(''))->toBeFalse();
    expect(EmployeeFacts::isInactive('TERMINATED'))->toBeFalse();
});

it('stages the status upper-case, as the retired view recorded it', function () {
    expect(EmployeeFacts::row(['personNumber' => '1', 'status' => 'Inactive'], 1)['assignment_status'])
        ->toBe('INACTIVE');
    expect(EmployeeFacts::row(['personNumber' => '1', 'status' => 'Active'], 1)['assignment_status'])
        ->toBe('ACTIVE');
    expect(EmployeeFacts::row(['personNumber' => '1'], 1)['assignment_status'])->toBeNull();
});

// ─── Gender, email ────────────────────────────────────────────────

it('maps Oracle\'s single-letter gender', function () {
    expect(EmployeeFacts::row(['personNumber' => '1', 'gender' => 'M'], 1)['gender'])->toBe('male');
    expect(EmployeeFacts::row(['personNumber' => '1', 'gender' => 'F'], 1)['gender'])->toBe('female');
    expect(EmployeeFacts::row(['personNumber' => '1'], 1)['gender'])->toBeNull();
    expect(EmployeeFacts::row(['personNumber' => '1', 'gender' => 'X'], 1)['gender'])->toBeNull();
});

it('lower-cases the email so the shared-address count can see duplicates', function () {
    expect(EmployeeFacts::row(['personNumber' => '1', 'personEmail' => 'A.Person@SamirGroup.com'], 1)['email'])
        ->toBe('a.person@samirgroup.com');
});

it('leaves a malformed address alone for mailboxOf to reject', function () {
    // It is not this class's job to judge it — mailboxOf() does, and it is
    // the one place that decision lives.
    expect(EmployeeFacts::row(['personNumber' => '1', 'personEmail' => 'a.alzamil2hotmail.com'], 1)['email'])
        ->toBe('a.alzamil2hotmail.com');
});
