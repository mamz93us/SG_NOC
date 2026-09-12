<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Audit\Auditor;
use Tests\Unit\Rbac\RbacTestSchema;

/**
 * The generic observer, end to end on a real model.
 *
 * The thing worth proving is that it records what the 20 hand-written observers
 * it replaced did not: writes with no signed-in user (the scheduler and the
 * queue, which is most of what this app does), with secrets redacted.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    RbacTestSchema::create();
    User::observe(\App\Observers\AuditObserver::class);
});

afterEach(fn () => RbacTestSchema::drop());

function makeUser(array $attributes = []): User
{
    static $n = 0;
    $n++;

    return User::create(array_merge([
        'name' => "Person {$n}",
        'email' => "p{$n}@example.com",
        'password' => 'secret-hash-value',
        'role' => 'viewer',
    ], $attributes));
}

it('records a create with the full row', function () {
    $user = makeUser(['name' => 'Ahmed']);

    $log = ActivityLog::where('model_type', User::class)->where('action', 'created')->latest('id')->first();

    expect($log)->not->toBeNull();
    expect($log->model_id)->toBe($user->id);
    expect($log->changes['name'])->toBe('Ahmed');
});

it('records an unattended write instead of dropping it', function () {
    // Nobody is signed in here — which is the state the scheduler, the queue and
    // every webhook run in. The old observers were all wrapped in
    // `if (Auth::check())`, so this produced no audit row at all.
    expect(auth()->check())->toBeFalse();

    makeUser();

    $log = ActivityLog::where('action', 'created')->latest('id')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBeNull();
    // …and it says who did it rather than leaving the actor blank.
    expect($log->actor_label)->toStartWith('console');
});

it('never writes a secret into the log', function () {
    $user = makeUser();

    $log = ActivityLog::where('action', 'created')->latest('id')->first();

    expect($log->changes['password'])->toBe('[redacted]');
    expect(json_encode($log->changes))->not->toContain('secret-hash-value');

    // And on update, where the old observers unset a hand-listed set of columns
    // and missed anything not on that list.
    $user->update(['password' => 'a-new-hash']);

    $updated = ActivityLog::where('action', 'updated')->latest('id')->first();

    expect($updated->changes['new']['password'])->toBe('[redacted]');
    expect(json_encode($updated->changes))->not->toContain('a-new-hash');
});

it('records only what changed on an update', function () {
    $user = makeUser(['name' => 'Before']);

    $user->update(['name' => 'After']);

    $log = ActivityLog::where('action', 'updated')->latest('id')->first();

    expect($log->changes['old'])->toBe(['name' => 'Before']);
    expect($log->changes['new'])->toBe(['name' => 'After']);
    // Not a dump of every column, which is what getOriginal() gave before.
    expect($log->changes['new'])->not->toHaveKey('email');
});

it('writes no row when nothing audit-worthy changed', function () {
    $user = makeUser();
    $before = ActivityLog::where('action', 'updated')->count();

    // Only an ignored column. A row here would mean every poll that stamps
    // last_login_at buries the real edits.
    $user->forceFill(['last_login_at' => now()])->save();

    expect(ActivityLog::where('action', 'updated')->count())->toBe($before);
});

it('captures a label so the log still names a deleted record', function () {
    $user = makeUser(['name' => 'Gone Person']);
    $id = $user->id;

    $user->delete();

    $log = ActivityLog::where('action', 'deleted')->latest('id')->first();

    expect($log->model_id)->toBe($id);
    expect($log->model_label)->toBe('Gone Person');
    expect($log->subjectName())->toBe('User: Gone Person');
});

it('writes nothing while auditing is suppressed', function () {
    $before = ActivityLog::count();

    Auditor::withoutAuditing(fn () => makeUser());

    expect(ActivityLog::count())->toBe($before);
});

it('attributes the write to the signed-in user when there is one', function () {
    $actor = makeUser(['name' => 'Actor']);
    $this->actingAs($actor);

    $target = makeUser(['name' => 'Target']);

    $log = ActivityLog::where('model_id', $target->id)->where('action', 'created')->first();

    expect($log->user_id)->toBe($actor->id);
    // actor_label stays null — user_id already says who it was.
    expect($log->actor_label)->toBeNull();
    expect($log->actorName())->toBe('Actor');
});
