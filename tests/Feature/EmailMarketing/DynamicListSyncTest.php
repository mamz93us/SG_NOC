<?php

use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\Employee;
use App\Services\EmailMarketing\DynamicListSyncService;

beforeEach(function () {
    $this->samirList = EmailList::create([
        'name'        => 'Samir Group employees',
        'auto_domain' => 'samirgroup.com',
    ]);
    $this->sssList = EmailList::create([
        'name'        => 'SSS Egypt employees',
        'auto_domain' => 'sssegypt.com',
    ]);
});

it('attaches a new active employee with a matching domain on create', function () {
    Employee::create([
        'name'   => 'Ahmed Saleh',
        'email'  => 'ahmed@samirgroup.com',
        'status' => 'active',
    ]);

    $sub = EmailSubscriber::where('email', 'ahmed@samirgroup.com')->first();
    expect($sub)->not->toBeNull();
    expect($sub->source)->toBe('employee');
    expect($this->samirList->subscribers()->where('email_subscribers.id', $sub->id)->exists())->toBeTrue();
    expect($this->sssList->subscribers()->where('email_subscribers.id', $sub->id)->exists())->toBeFalse();
});

it('routes employees to the correct dynamic list by domain', function () {
    $a = Employee::create(['name' => 'A', 'email' => 'a@samirgroup.com', 'status' => 'active']);
    $b = Employee::create(['name' => 'B', 'email' => 'b@sssegypt.com',   'status' => 'active']);

    expect($this->samirList->subscribers()->count())->toBe(1);
    expect($this->sssList->subscribers()->count())->toBe(1);
    expect($this->samirList->subscribers()->first()->email)->toBe('a@samirgroup.com');
    expect($this->sssList->subscribers()->first()->email)->toBe('b@sssegypt.com');
});

it('does not attach employees whose domain matches no dynamic list', function () {
    Employee::create(['name' => 'X', 'email' => 'x@external.com', 'status' => 'active']);

    expect($this->samirList->subscribers()->count())->toBe(0);
    expect($this->sssList->subscribers()->count())->toBe(0);
});

it('detaches an employee when their status flips to terminated', function () {
    $emp = Employee::create(['name' => 'A', 'email' => 'a@samirgroup.com', 'status' => 'active']);
    expect($this->samirList->subscribers()->count())->toBe(1);

    $emp->update(['status' => 'terminated']);

    expect($this->samirList->subscribers()->count())->toBe(0);
    // Subscriber row is preserved — only the pivot is removed.
    expect(EmailSubscriber::where('email', 'a@samirgroup.com')->exists())->toBeTrue();
});

it('moves an employee between lists when their email domain changes', function () {
    $emp = Employee::create(['name' => 'A', 'email' => 'a@samirgroup.com', 'status' => 'active']);
    expect($this->samirList->subscribers()->count())->toBe(1);

    $emp->update(['email' => 'a@sssegypt.com']);

    expect($this->samirList->subscribers()->count())->toBe(0);
    expect($this->sssList->subscribers()->count())->toBe(1);
    expect($this->sssList->subscribers()->first()->email)->toBe('a@sssegypt.com');
});

it('detaches when an employee is deleted', function () {
    $emp = Employee::create(['name' => 'A', 'email' => 'a@samirgroup.com', 'status' => 'active']);
    expect($this->samirList->subscribers()->count())->toBe(1);

    $emp->delete();

    expect($this->samirList->subscribers()->count())->toBe(0);
});

it('full reconciliation backfills, removes, and ignores non-matching employees', function () {
    // Bypass the observer so we can simulate pre-existing drift.
    Employee::withoutEvents(function () {
        Employee::create(['name' => 'A', 'email' => 'a@samirgroup.com', 'status' => 'active']);
        Employee::create(['name' => 'B', 'email' => 'b@samirgroup.com', 'status' => 'terminated']);
        Employee::create(['name' => 'C', 'email' => 'c@external.com',   'status' => 'active']);
    });

    // Seed a stale pivot entry that should be removed by reconciliation.
    $stale = EmailSubscriber::create(['email' => 'gone@samirgroup.com', 'status' => 'subscribed']);
    $this->samirList->subscribers()->attach($stale->id, ['subscribed_at' => now()]);

    $totals = app(DynamicListSyncService::class)->syncAll();

    expect($totals['added'])->toBe(1);     // A added
    expect($totals['removed'])->toBe(1);   // stale subscriber pruned
    expect($this->samirList->subscribers()->pluck('email')->all())->toBe(['a@samirgroup.com']);
});

it('case-insensitively matches domains and normalizes the subscriber email', function () {
    Employee::create(['name' => 'A', 'email' => 'A.Saleh@SamirGroup.COM', 'status' => 'active']);

    expect($this->samirList->subscribers()->count())->toBe(1);
    expect($this->samirList->subscribers()->first()->email)->toBe('a.saleh@samirgroup.com');
});
