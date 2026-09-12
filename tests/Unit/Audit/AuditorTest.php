<?php

use App\Support\Audit\Auditor;
use Illuminate\Database\Eloquent\Model;

/**
 * Auditor is the one place an audit row is shaped, so these cover the parts
 * that decide whether the log is trustworthy: what gets redacted, what counts
 * as a change, and suppression.
 *
 * Replaces tests/Unit/EmailMarketingAuditTest.php, which tested the same
 * suppression contract on EmailMarketingActivityObserver::silently() — that
 * observer was one of 20 audit-only observers folded into AuditObserver.
 *
 * Needs the app booted (not the database): the redact and ignore lists are
 * read from config/audit.php, which is the point — they are meant to be
 * editable without touching this class.
 */
uses(Tests\TestCase::class);

/**
 * A bare model with no table, standing in for one that has just been saved.
 *
 * Auditor::diff() reads getChanges(), which Eloquent only populates during
 * save() — so the fixture calls syncChanges() to put the model in the state an
 * `updated` observer actually sees.
 */
function auditFixture(array $attributes = [], array $original = []): Model
{
    $model = new class extends Model
    {
        protected $guarded = [];

        public function stageSave(array $attributes, array $original): static
        {
            $this->setRawAttributes($original, true); // also syncs original
            $this->forceFill($attributes);
            $this->syncChanges();                     // changes = dirty, as after save()

            return $this;
        }
    };

    return $model->stageSave($attributes, $original);
}

// ── Suppression ─────────────────────────────────────────────────

it('suppresses auditing inside the callback and restores afterwards', function () {
    expect(Auditor::muted())->toBeFalse();

    $inside = null;
    $returned = Auditor::withoutAuditing(function () use (&$inside) {
        $inside = Auditor::muted();

        return 'ok';
    });

    expect($inside)->toBeTrue();
    expect($returned)->toBe('ok');
    expect(Auditor::muted())->toBeFalse();
});

it('restores auditing even when the callback throws', function () {
    try {
        Auditor::withoutAuditing(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    expect(Auditor::muted())->toBeFalse();
});

it('counts nested suppression so an inner block cannot re-enable the outer one', function () {
    Auditor::withoutAuditing(function () {
        Auditor::withoutAuditing(fn () => null);

        // Still muted: the inner block finishing must not undo the outer one.
        expect(Auditor::muted())->toBeTrue();
    });

    expect(Auditor::muted())->toBeFalse();
});

// ── Redaction ───────────────────────────────────────────────────

it('redacts by column name, not by value', function () {
    expect(Auditor::scrub('password', 'hunter2'))->toBe('[redacted]');
    expect(Auditor::scrub('api_key', 'AKIA1234'))->toBe('[redacted]');
    expect(Auditor::scrub('two_factor_secret', 'JBSWY3DP'))->toBe('[redacted]');
    expect(Auditor::scrub('snmp_community', 'public'))->toBe('[redacted]');
    expect(Auditor::scrub('client_secret', 'x'))->toBe('[redacted]');

    // Non-secret columns pass through untouched.
    expect(Auditor::scrub('name', 'JED Firewall'))->toBe('JED Firewall');
    expect(Auditor::scrub('ip_address', '10.1.0.1'))->toBe('10.1.0.1');
});

it('redacts a rotated secret on both sides of the diff but still records that it changed', function () {
    $model = auditFixture(
        ['name' => 'Sophos JED', 'password' => 'new-secret'],
        ['id' => 1, 'name' => 'Sophos JED', 'password' => 'old-secret'],
    );

    $diff = Auditor::diff($model);

    expect($diff)->not->toBeNull();
    expect($diff['old'])->toHaveKey('password');
    expect($diff['old']['password'])->toBe('[redacted]');
    expect($diff['new']['password'])->toBe('[redacted]');

    // The unchanged `name` is not in the diff at all.
    expect($diff['new'])->not->toHaveKey('name');
});

it('never lets a null secret leak as the literal null-vs-value pair', function () {
    $model = auditFixture(
        ['api_key' => 'live-key'],
        ['id' => 1, 'api_key' => null],
    );

    $diff = Auditor::diff($model);

    expect($diff['old']['api_key'])->toBeNull();          // there was nothing before
    expect($diff['new']['api_key'])->toBe('[redacted]');  // and the new value is hidden
});

// ── What counts as a change ─────────────────────────────────────

it('returns null when only ignored columns changed', function () {
    // A poll stamping last_seen_at is not an audit event; a row per poll per
    // host would bury every real edit.
    $model = auditFixture(
        ['last_seen_at' => '2026-09-12 10:00:00', 'updated_at' => '2026-09-12 10:00:00'],
        ['id' => 1, 'last_seen_at' => '2026-09-12 09:55:00', 'updated_at' => '2026-09-12 09:55:00'],
    );

    expect(Auditor::diff($model))->toBeNull();
});

it('drops ignored columns but keeps the real change alongside them', function () {
    $model = auditFixture(
        ['status' => 'terminated', 'updated_at' => '2026-09-12 10:00:00'],
        ['id' => 1, 'status' => 'active', 'updated_at' => '2026-09-12 09:00:00'],
    );

    $diff = Auditor::diff($model);

    expect($diff['new'])->toBe(['status' => 'terminated']);
    expect($diff['old'])->toBe(['status' => 'active']);
    expect($diff['new'])->not->toHaveKey('updated_at');
});

it('ignores campaign counters so a send does not look like an edit', function () {
    $model = auditFixture(
        ['total_opens' => 412, 'total_clicks' => 88],
        ['id' => 1, 'total_opens' => 400, 'total_clicks' => 80],
    );

    expect(Auditor::diff($model))->toBeNull();
});

// ── Size guards ─────────────────────────────────────────────────

it('truncates a long string rather than copying a blob into every audit row', function () {
    $long = str_repeat('a', 5000);

    $result = Auditor::scrub('design_json', $long);

    expect($result)->toBeString();
    expect(mb_strlen($result))->toBeLessThan(2100);
    expect($result)->toContain('5000 chars');
});

it('summarises a large array instead of encoding all of it', function () {
    $big = array_fill(0, 2000, 'some-reasonably-long-value-per-entry');

    expect(Auditor::scrub('payload', $big))->toBe('[2000 items, truncated]');
});

it('keeps a small array intact', function () {
    expect(Auditor::scrub('surfaces', ['noc_admin', 'hr_portal']))
        ->toBe(['noc_admin', 'hr_portal']);
});

// ── Labelling ───────────────────────────────────────────────────

it('describes a record by the first human-readable attribute it has', function () {
    expect(Auditor::describe(auditFixture(['name' => 'JED Firewall'])))->toBe('JED Firewall');
    expect(Auditor::describe(auditFixture(['title' => 'Onboard Ahmed'])))->toBe('Onboard Ahmed');
    expect(Auditor::describe(auditFixture(['hostname' => 'sw-jed-01'])))->toBe('sw-jed-01');
    expect(Auditor::describe(auditFixture(['email' => 'a@b.com'])))->toBe('a@b.com');

    // Nothing recognisable: the caller falls back to class + id.
    expect(Auditor::describe(auditFixture(['weight' => 12])))->toBeNull();
});

it('attributes console work to the command rather than to a person', function () {
    // Not signed in and running in the console: the label names the command, so
    // an unattended change reads as "console: biotime:sync" instead of being
    // booked to whichever user happened to sign up first.
    $label = Auditor::actorLabel();

    expect($label)->toBeString();
    expect($label)->toStartWith('console');
});
