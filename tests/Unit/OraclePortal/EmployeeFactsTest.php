<?php

use App\Services\OraclePortal\EmployeeFacts;
use Carbon\CarbonImmutable;

/**
 * Oracle's /attendance view onto the HR import's staged row shape.
 *
 * The hire-date cases are the reason this class exists separately from the
 * vacation importer's date parser, which bounds years to 2000-2100. That is
 * right for leave and wrong here: 21 of the 617 people in this feed were hired
 * before 2000, the oldest in 1988.
 */
it('maps an attendance row onto the staged shape', function () {
    $row = EmployeeFacts::row([
        'personNumber' => '1003',
        'personName' => 'Jaison Joseph',
        'personEmail' => 'jaison.joseph@samirgroup.com',
        'employeeCategory' => 'SALES',
        'orgName' => 'Customer Equipment Services Copiers - Riyadh',
        'locationName' => 'Riyadh',
        'jobName' => 'Technician',
        'gender' => 'M',
        'personId' => '100000000367262',
        'assignmentId' => '300000002516104',
        'assignmentStatusType' => 'ACTIVE',
        'personType' => 'Permanent Employee',
        'supervisorName' => 'Wilbert Reyes',
        'managerName' => 'Alaa Ghonim',
        'startDate' => '17-OCT-09',
    ], 1);

    expect($row)->toMatchArray([
        'emp_no' => '1003',
        'emp_name' => 'Jaison Joseph',
        'email' => 'jaison.joseph@samirgroup.com',
        'employee_category' => 'SALES',
        'dept_name' => 'Customer Equipment Services Copiers - Riyadh',
        'location_name' => 'Riyadh',
        'job_name' => 'Technician',
        'gender' => 'male',
        'person_id' => '100000000367262',
        'assignment_status' => 'ACTIVE',
        'hire_date' => '2009-10-17',
    ]);
});

it('sends no mobile number or dept no, because the API has neither', function () {
    // This is why the API cannot replace the spreadsheet import, and why
    // writeToEmployee()'s never-write-an-empty-value rule matters: without it,
    // an API pull would blank 600 mobile numbers the sheet had filled.
    $row = EmployeeFacts::row(['personNumber' => '1003'], 1);

    expect($row['mobile_no'])->toBe('');
    expect($row['dept_no'])->toBe('');
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

it('parses a hire date from this century', function () {
    expect(EmployeeFacts::hireDate('17-OCT-09')?->toDateString())->toBe('2009-10-17');
    expect(EmployeeFacts::hireDate('01-JAN-26')?->toDateString())->toBe('2026-01-01');
});

it('parses a hire date from before 2000, which the leave parser rejects', function () {
    // The oldest person in the live feed started in 1988. PHP reads a two-digit
    // year as 1970-1999 for 70-99, which is what makes this work.
    expect(EmployeeFacts::hireDate('02-JAN-88')?->toDateString())->toBe('1988-01-02');
    expect(EmployeeFacts::hireDate('09-AUG-95')?->toDateString())->toBe('1995-08-09');
});

it('refuses a date before anybody could have been hired', function () {
    expect(EmployeeFacts::hireDate('01-JAN-55'))->toBeNull();
});

it('reads a two-digit year the way the feed means it', function () {
    // 00-69 is this century and 70-99 the last, which is exactly right across
    // the 1988-2026 span the feed actually covers. So '99' is 1999 — a normal
    // hire date, not a future one.
    expect(EmployeeFacts::hireDate('01-JAN-99')?->toDateString())->toBe('1999-01-01');
    expect(EmployeeFacts::hireDate('02-DEC-09')?->toDateString())->toBe('2009-12-02');

    // The far end of the pivot, 2069, is caught by the future guard instead —
    // the two rules together leave only dates somebody could actually have
    // started on.
    expect(EmployeeFacts::hireDate('01-JAN-69'))->toBeNull();
});

it('refuses a date far in the future, which is a typo not a start date', function () {
    $now = CarbonImmutable::parse('2026-09-20');

    expect(EmployeeFacts::hireDate('01-JAN-40', $now))->toBeNull();
    // A start date next month is perfectly normal, though.
    expect(EmployeeFacts::hireDate('01-NOV-26', $now)?->toDateString())->toBe('2026-11-01');
});

it('refuses a day that does not exist rather than rolling it over', function () {
    // Carbon's default is to overflow: '31-FEB-20' becomes 2 March. A hire date
    // two days from what the feed said is worse than no hire date, because
    // nobody looking at it later could tell.
    expect(EmployeeFacts::hireDate('31-FEB-20'))->toBeNull();
    expect(EmployeeFacts::hireDate('31-APR-24'))->toBeNull();
    // A real 29 February still works.
    expect(EmployeeFacts::hireDate('29-FEB-24')?->toDateString())->toBe('2024-02-29');
});

it('returns null rather than throwing on an unreadable date', function () {
    // Carbon is in strict mode, so a bad format throws. One odd row must not
    // abort a pull of the whole workforce.
    expect(EmployeeFacts::hireDate('2026-09-17'))->toBeNull();
    expect(EmployeeFacts::hireDate(''))->toBeNull();
    expect(EmployeeFacts::hireDate(null))->toBeNull();
    expect(EmployeeFacts::hireDate('not a date'))->toBeNull();
});

// ─── Assignment status ────────────────────────────────────────────

it('treats only an explicit INACTIVE as inactive', function () {
    expect(EmployeeFacts::isInactive('INACTIVE'))->toBeTrue();
    expect(EmployeeFacts::isInactive('inactive'))->toBeTrue();
    expect(EmployeeFacts::isInactive(' INACTIVE '))->toBeTrue();

    expect(EmployeeFacts::isInactive('ACTIVE'))->toBeFalse();
    // Absence is not a statement. A person Oracle does not mention at all —
    // every SSS Egypt employee, for instance — has no status here, and that
    // must never read as "has left".
    expect(EmployeeFacts::isInactive(null))->toBeFalse();
    expect(EmployeeFacts::isInactive(''))->toBeFalse();
    expect(EmployeeFacts::isInactive('TERMINATED'))->toBeFalse();
});

it('upper-cases the assignment status it stages', function () {
    expect(EmployeeFacts::row(['personNumber' => '1', 'assignmentStatusType' => 'inactive'], 1)['assignment_status'])
        ->toBe('INACTIVE');
});

// ─── Gender ───────────────────────────────────────────────────────

it('maps Oracle\'s single-letter gender', function () {
    expect(EmployeeFacts::row(['personNumber' => '1', 'gender' => 'M'], 1)['gender'])->toBe('male');
    expect(EmployeeFacts::row(['personNumber' => '1', 'gender' => 'F'], 1)['gender'])->toBe('female');
    expect(EmployeeFacts::row(['personNumber' => '1'], 1)['gender'])->toBeNull();
    expect(EmployeeFacts::row(['personNumber' => '1', 'gender' => 'X'], 1)['gender'])->toBeNull();
});

// ─── Fields Oracle declares but never sends ───────────────────────

it('reads the Arabic name as absent, which is what Oracle sends today', function () {
    // personNameAr is in the OpenAPI schema and NULL for all 617 people, and
    // null fields are omitted from the JSON entirely. Mapped anyway so it
    // arrives by itself once HR fills the column in.
    expect(EmployeeFacts::row(['personNumber' => '1'], 1)['person_name_ar'])->toBeNull();
    expect(EmployeeFacts::row(['personNumber' => '1', 'personNameAr' => 'جيسون'], 1)['person_name_ar'])
        ->toBe('جيسون');
});

it('lower-cases the email so the shared-address count can see duplicates', function () {
    expect(EmployeeFacts::row(['personNumber' => '1', 'personEmail' => 'A.Person@SamirGroup.com'], 1)['email'])
        ->toBe('a.person@samirgroup.com');
});

it('leaves a malformed address alone for mailboxOf to reject', function () {
    // The live feed carries one address with no @ at all. It is not this
    // class's job to judge it — mailboxOf() does, and it is the one place that
    // decision lives.
    expect(EmployeeFacts::row(['personNumber' => '1', 'personEmail' => 'a.alzamil2hotmail.com'], 1)['email'])
        ->toBe('a.alzamil2hotmail.com');
});
